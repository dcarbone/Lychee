<?php

namespace App\Relations;

use App\Actions\AlbumAuthorisationProvider;
use App\Actions\PhotoAuthorisationProvider;
use App\Facades\AccessControl;
use App\Models\Album;
use App\Models\Configs;
use App\Models\Extensions\Thumb;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\JoinClause;

class HasAlbumThumb extends Relation
{
	protected AlbumAuthorisationProvider $albumAuthorisationProvider;
	protected PhotoAuthorisationProvider $photoAuthorisationProvider;
	protected string $sortingCol;
	protected string $sortingOrder;

	public function __construct(Album $parent)
	{
		// Sic! We must initialize attributes of this class before we call
		// the parent constructor.
		// The parent constructor calls `addConstraints` and thus our own
		// attributes must be initialized by then
		$this->albumAuthorisationProvider = resolve(AlbumAuthorisationProvider::class);
		$this->photoAuthorisationProvider = resolve(PhotoAuthorisationProvider::class);
		$this->sortingCol = Configs::get_value('sorting_Photos_col');
		$this->sortingOrder = Configs::get_value('sorting_Photos_order');
		parent::__construct(
			Photo::query()->with(['size_variants' => fn (HasMany $r) => Thumb::sizeVariantsFilter($r)]),
			$parent
		);
	}

	/**
	 * Adds the constraints for a single album.
	 *
	 * If the album has set an explicit cover, then we simply search for that
	 * photo.
	 * Else, we search for all photos which are (recursive) descendants of the
	 * given album.
	 */
	public function addConstraints(): void
	{
		if (static::$constraints) {
			/** @var Album $album */
			$album = $this->parent;
			if ($album->cover_id) {
				$this->where('photos.id', '=', $album->cover_id);
			} else {
				$this->photoAuthorisationProvider
					->applySearchabilityFilter($this->query, $album);
			}
		}
	}

	/**
	 * Builds a query to eagerly load the thumbnails of a sequence of albums.
	 *
	 * Note, the query is not as efficient as it could be, but it is the
	 * best query we can construct which is portable to MySQL, PostgreSQl and
	 * SQLite.
	 * The inefficiency comes from the inner, correlated value sub-query
	 * `bestPhotoIDSelect`.
	 * This value query refers the outer query through `covered_albums` and
	 * thus needs to be executed for every result.
	 * Moreover, the temporary query table `$album2Cover` is an in-memory
	 * table and thus does not provide any indexes.
	 *
	 * A faster approach would be to first JOIN the tables, then sort the
	 * result and finally pick the first result of each group based on
	 * identical `covered_album_id`.
	 * The approach "join first (with everything), filter last" is faster,
	 * because the DBMS can use its indexes.
	 *
	 * For PostgreSQL we could use the `DISTINCT ON`-clause to achieve the
	 * result:
	 *
	 *     SELECT DISTINCT ON (covered_album_id)
	 *       covered_albums.id AS covered_album_id,
	 *       photos.id         AS id,
	 *       photos.type       AS type
	 *     FROM covered_albums
	 *     LEFT JOIN
	 *       (
	 *         photos
	 *         LEFT JOIN albums
	 *         ON (albums.id = photos.album_id)
	 *       )
	 *     ON (
	 *       albums._lft >= covered_albums._lft AND
	 *       albums._rgt <= covered_albums._rgt AND
	 *       "complicated seachability filter goes here"
	 *     )
	 *     WHERE covered_albums.id IN $albumKeys
	 *     ORDER BY album_id ASC, photos.is_starred DESC, photos.created_at DESC
	 *
	 * For PostgreSQL see ["SELECT - DISTINCT Clause"](https://www.postgresql.org/docs/13/sql-select.html#SQL-DISTINCT).
	 *
	 * But `DISTINCT ON` is provided by neither MySQL nor SQLite.
	 * For the latter two, the following non-SQL-conformant query could be
	 * used:
	 *
	 *     SELECT
	 *       covered_albums.id  AS covered_album_id,
	 *       photos.id          AS id,
	 *       photos.type        AS type
	 *     FROM covered_albums
	 *     LEFT JOIN
	 *       (
	 *         photos
	 *         LEFT JOIN albums
	 *         ON (albums.id = photos.album_id)
	 *       )
	 *     ON (
	 *       albums._lft >= covered_albums._lft AND
	 *       albums._rgt <= covered_albums._rgt AND
	 *       "complicated seachability filter goes here"
	 *     )
	 *     WHERE covered_albums.id IN $albumKeys
	 *     ORDER BY album_id ASC, photos.is_starred DESC, photos.created_at DESC
	 *     GROUP BY album_id
	 *
	 * Instead of enforcing distinct results for `covered_album_id`, the result
	 * is grouped by `covered_album_id`.
	 * Note that this is not SQL-compliant, because the `SELECT` clause
	 * contains two columns (`photo.id` and `photo.type`) which are neither
	 * part of the `GROUP BY`-clause nor aggregates.
	 * However, MySQL and SQLite relax this constraint and return the
	 * column values of the first row of a group.
	 * This is exactly the specified behaviour of `DISTINCT ON`.
	 * For SQLite see "[Quirks, Caveats, and Gotchas In SQLite, Sec. 6](https://www.sqlite.org/quirks.html)"
	 *
	 * TODO: If the following query is too slow for large installation, we must write two separate implementations for PostgreSQL and MySQL/SQLite as outlined above.
	 *
	 * @param array<Album> $models
	 */
	public function addEagerConstraints(array $models): void
	{
		// We only use those `Album` models which have not set an explicit
		// cover.
		// Albums with explicit covers are treated separately in
		// method `match`.
		$albumKeys = collect($models)
			->whereNull('cover_id')
			->unique('id', true)
			->sortBy('id')
			->map(fn (Album $album) => $album->getKey())
			->values();

		$bestPhotoIDSelect = Photo::query()
			->select(['photos.id AS photo_id'])
			->join('albums', 'albums.id', '=', 'photos.album_id')
			->whereColumn('albums._lft', '>=', 'covered_albums._lft')
			->whereColumn('albums._rgt', '<=', 'covered_albums._rgt')
			->orderBy('photos.is_starred', 'desc')
			->orderBy('photos.' . $this->sortingCol, $this->sortingOrder)
			->limit(1);
		if (!AccessControl::is_admin()) {
			$bestPhotoIDSelect->where(function (Builder $query2) {
				$this->photoAuthorisationProvider->appendSearchabilityConditions(
					$query2->getQuery(),
					'covered_albums._lft',
					'covered_albums._rgt'
				);
			});
		}

		$userID = AccessControl::is_logged_in() ? AccessControl::id() : null;

		$album2Cover = function (BaseBuilder $builder) use ($bestPhotoIDSelect, $albumKeys, $userID) {
			$builder
				->from('albums as covered_albums')
				->join('base_albums', 'base_albums.id', '=', 'covered_albums.id');
			if ($userID !== null) {
				$builder->leftJoin('user_base_album',
					function (JoinClause $join) use ($userID) {
						$join
							->on('user_base_album.base_album_id', '=', 'base_albums.id')
							->where('user_base_album.user_id', '=', $userID);
					}
				);
			}
			$builder->select(['covered_albums.id AS album_id'])
				->addSelect(['photo_id' => $bestPhotoIDSelect])
				->whereIn('covered_albums.id', $albumKeys);
			if (!AccessControl::is_admin()) {
				$builder->where(function (BaseBuilder $q) {
					$this->albumAuthorisationProvider->appendAccessibilityConditions($q);
				});
			}
		};

		$this->query
			->select([
				'covers.id as id',
				'covers.type as type',
				'album_2_cover.album_id as covered_album_id',
			])
			->from($album2Cover, 'album_2_cover')
			->join(
				'photos as covers',
				'covers.id',
				'=',
				'album_2_cover.photo_id'
			);
	}

	/**
	 * @param array<Album> $models   an array of albums models whose thumbnails shall be initialized
	 * @param string       $relation the name of the relation from the parent to the child models
	 *
	 * @return array the array of album models
	 */
	public function initRelation(array $models, $relation): array
	{
		foreach ($models as $model) {
			$model->setRelation($relation, null);
		}

		return $models;
	}

	/**
	 * Match the eagerly loaded results to their parents.
	 *
	 * @param array<Album>      $models   an array of parent models
	 * @param Collection<Photo> $results  the unified collection of all child models of all parent models
	 * @param string            $relation the name of the relation from the parent to the child models
	 *
	 * @return array
	 */
	public function match(array $models, Collection $results, $relation): array
	{
		$dictionary = $results->mapToDictionary(function ($result) {
			return [$result->covered_album_id => $result];
		})->all();

		// Once we have the dictionary we can simply spin through the parent models to
		// link them up with their children using the keyed dictionary to make the
		// matching very convenient and easy work. Then we'll just return them.
		/** @var Album $album */
		foreach ($models as $album) {
			$albumID = $album->id;
			if ($album->cover_id) {
				// We do not execute a query, if `cover_id` is set, because
				// `Album`always eagerly loads its cover and hence, we already
				// have it.
				// See {@link Album::with}
				$album->setRelation($relation, Thumb::createFromPhoto($album->cover));
			} elseif (isset($dictionary[$albumID])) {
				/** @var Photo $cover */
				$cover = reset($dictionary[$albumID]);
				$album->setRelation($relation, Thumb::createFromPhoto($cover));
			} else {
				$album->setRelation($relation, null);
			}
		}

		return $models;
	}

	public function getResults(): ?Thumb
	{
		/** @var Album $album */
		$album = $this->parent;
		if ($album === null || !$this->albumAuthorisationProvider->isAccessible($album)) {
			return null;
		}

		// We do not execute a query, if `cover_id` is set, because `Album`
		// is always eagerly loaded with its cover and hence, we already
		// have it.
		// See {@link Album::with}
		if ($album->cover_id) {
			return Thumb::createFromPhoto($album->cover);
		} else {
			return Thumb::createFromQueryable(
				$this->query, $this->sortingCol, $this->sortingOrder
			);
		}
	}
}
