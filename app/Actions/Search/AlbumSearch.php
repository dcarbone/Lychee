<?php

namespace App\Actions\Search;

use App\Actions\AlbumAuthorisationProvider;
use App\Models\Album;
use App\Models\Configs;
use App\Models\Extensions\SortingDecorator;
use App\Models\TagAlbum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AlbumSearch
{
	protected AlbumAuthorisationProvider $albumAuthorisationProvider;

	public function __construct(AlbumAuthorisationProvider $albumAuthorisationProvider)
	{
		$this->albumAuthorisationProvider = $albumAuthorisationProvider;
	}

	public function query(array $terms): Collection
	{
		$sortingCol = Configs::get_value('sorting_Albums_col', 'created_at');
		$sortingOrder = Configs::get_value('sorting_Albums_order', 'ASC');

		$tagAlbums = (new SortingDecorator($this->createTagAlbumQuery($terms)))
			->orderBy($sortingCol, $sortingOrder)
			->get();
		$albums = (new SortingDecorator($this->createAlbumQuery($terms)))
			->orderBy($sortingCol, $sortingOrder)
			->get();

		return $tagAlbums->concat($albums);
	}

	private function createAlbumQuery($terms): Builder
	{
		$albumQuery = Album::query()
			->select(['albums.*'])
			->join('base_albums', 'base_albums.id', '=', 'albums.id');
		$this->addSearchCondition($terms, $albumQuery);
		$this->albumAuthorisationProvider->applyBrowsabilityFilter($albumQuery);

		return $albumQuery;
	}

	private function createTagAlbumQuery(array $terms): Builder
	{
		// Note: `applyVisibilityFilter` already adds a JOIN clause with `base_albums`.
		// No need to add a second JOIN clause.
		$albumQuery = $this->albumAuthorisationProvider->applyVisibilityFilter(
			TagAlbum::query()
		);
		$this->addSearchCondition($terms, $albumQuery);

		return $albumQuery;
	}

	private function addSearchCondition(array $terms, Builder $query): Builder
	{
		foreach ($terms as $term) {
			$query->where(
				fn (Builder $query) => $query
					->where('base_albums.title', 'like', '%' . $term . '%')
					->orWhere('base_albums.description', 'like', '%' . $term . '%')
			);
		}

		return $query;
	}
}
