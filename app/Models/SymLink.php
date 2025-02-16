<?php

namespace App\Models;

use App\Casts\MustNotSetCast;
use App\Facades\Helpers;
use App\Models\Extensions\HasAttributesPatch;
use App\Models\Extensions\UTCBasedTimes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * App\SymLink.
 *
 * @property int $id
 * @property int $size_variant_id
 * @property SizeVariant size_variant
 * @property string $short_path
 * @property string $full_path
 * @property string $url
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder expired()
 */
class SymLink extends Model
{
	use UTCBasedTimes;
	use HasAttributesPatch;

	public const DISK_NAME = 'symbolic';

	protected $casts = [
		'id' => 'integer',
		'size_variant_id' => 'integer',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
		'url' => MustNotSetCast::class,
	];

	/**
	 * @var string[] The list of attributes which exist as columns of the DB
	 *               relation but shall not be serialized to JSON
	 */
	protected $hidden = [
		'size_variant', // see above and otherwise infinite loops will occur
		'size_variant_id', // see above
	];

	public function size_variant(): BelongsTo
	{
		return $this->belongsTo(SizeVariant::class);
	}

	/**
	 * Scopes the passed query to all outdated symlinks.
	 *
	 * @param Builder $query the unscoped query
	 *
	 * @return Builder the scoped query
	 */
	public function scopeExpired(Builder $query): Builder
	{
		$expiration = now()->subDays(intval(Configs::get_value('SL_life_time_days', '3')));

		return $query->where('created_at', '<', $this->fromDateTime($expiration));
	}

	/**
	 * Accessor for the "virtual" attribute {@link SymLink::$url}.
	 *
	 * Returns the URL to the symbolic link from the perspective of a
	 * web client.
	 * This is a convenient method and wraps {@link SymLink::$short_path}
	 * into {@link \Illuminate\Support\Facades\Storage::url()}.
	 *
	 * @return string the URL to the symbolic link
	 */
	protected function getUrlAttribute(): string
	{
		return Storage::disk(self::DISK_NAME)->url($this->short_path);
	}

	/**
	 * Accessor for the "virtual" attribute {@link SymLink::$full_path}.
	 *
	 * Returns the full path of the symbolic link as it needs to be input into
	 * some low-level PHP functions like `unlink`.
	 * This is a convenient method and wraps {@link SymLink::$short_path}
	 * into {@link \Illuminate\Support\Facades\Storage::path()}.
	 *
	 * @return string the full path of the symbolic link
	 */
	protected function getFullPathAttribute(): string
	{
		return Storage::disk(self::DISK_NAME)->path($this->short_path);
	}

	/**
	 * Performs the `INSERT` operation of the model and creates an actual
	 * symbolic link on disk.
	 *
	 * If this method cannot create the symbolic link, then this method
	 * cancels the insert operation.
	 *
	 * @param Builder $query
	 *
	 * @return bool
	 */
	protected function performInsert(Builder $query): bool
	{
		$origFullPath = $this->size_variant->full_path;
		$extension = Helpers::getExtension($origFullPath);
		$symShortPath = hash('sha256', random_bytes(32) . '|' . $origFullPath) . $extension;
		$symFullPath = Storage::disk(SymLink::DISK_NAME)->path($symShortPath);
		if (is_link($symFullPath)) {
			unlink($symFullPath);
		}
		if (!symlink($origFullPath, $symFullPath)) {
			return false;
		}
		$this->short_path = $symShortPath;

		return parent::performInsert($query);
	}

	/**
	 * Deletes the model from the database and the symbolic link from storage.
	 *
	 * If this method cannot delete the symbolic link, then this method
	 * cancels the delete operation.
	 *
	 * @return bool
	 */
	public function delete(): bool
	{
		$fullPath = $this->full_path;
		// Laravel and Flysystem does not support symbolic links.
		// So we must use low-level methods here.
		if ((is_link($fullPath) && !unlink($fullPath)) || (file_exists($fullPath)) && !is_link($fullPath)) {
			return false;
		}

		return parent::delete() !== false;
	}
}
