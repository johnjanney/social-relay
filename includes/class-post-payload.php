<?php
/**
 * Everything a provider needs to publish one post.
 *
 * Deliberately provider-agnostic: nothing in this object mentions X. Adding a
 * second network later is one new class implementing SRL_Provider, with no
 * change here. SPEC.md section 15.3.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable payload for one send.
 */
class SRL_Post_Payload {

	/**
	 * Post title, as it reads at send time.
	 *
	 * @var string
	 */
	public string $title;

	/**
	 * Permalink. Never truncated, never shortened.
	 *
	 * @var string
	 */
	public string $permalink;

	/**
	 * Absolute path to the image file, or null.
	 *
	 * @var string|null
	 */
	public ?string $image_path;

	/**
	 * MIME type of the image, or null.
	 *
	 * @var string|null
	 */
	public ?string $image_mime;

	/**
	 * A media id already uploaded for this post and still valid, or null.
	 *
	 * Set on a retry so the same image is not paid for twice. SPEC.md 9.1.
	 *
	 * @var string|null
	 */
	public ?string $existing_media_id;

	/**
	 * Optional prefix.
	 *
	 * @var string
	 */
	public string $prefix;

	/**
	 * Optional suffix.
	 *
	 * @var string
	 */
	public string $suffix;

	/**
	 * Constructor.
	 *
	 * @param string      $title             Post title.
	 * @param string      $permalink         Permalink.
	 * @param string|null $image_path        Absolute path, or null.
	 * @param string|null $image_mime        MIME type, or null.
	 * @param string      $prefix            Optional prefix.
	 * @param string      $suffix            Optional suffix.
	 * @param string|null $existing_media_id Reusable media id, or null.
	 */
	public function __construct(
		string $title,
		string $permalink,
		?string $image_path = null,
		?string $image_mime = null,
		string $prefix = '',
		string $suffix = '',
		?string $existing_media_id = null
	) {
		$this->title             = $title;
		$this->permalink         = $permalink;
		$this->image_path        = $image_path;
		$this->image_mime        = $image_mime;
		$this->prefix            = $prefix;
		$this->suffix            = $suffix;
		$this->existing_media_id = $existing_media_id;
	}

	/**
	 * Whether an image should be attempted.
	 *
	 * @return bool
	 */
	public function has_image(): bool {
		return null !== $this->existing_media_id
			|| ( null !== $this->image_path && '' !== $this->image_path );
	}
}
