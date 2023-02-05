<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\DiscordNotifications;

class DiscordEmbedBuilder {

	/**
	 * The color of the embed.
	 *
	 * @var string
	 */
	private $color;

	/**
	 * The description of the embed.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * The username to display with the embed.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * The URL of the avatar to display with the embed.
	 *
	 * @var string
	 */
	private $avatarUrl;

	/**
	 * Sets the color of the embed.
	 *
	 * @param string $color The color to set, in hexadecimal format.
	 *
	 * @return self
	 */
	public function setColor( string $color ): self {
		$this->color = $color;

		return $this;
	}

	/**
	 * Sets the description of the embed.
	 *
	 * @param string $description The description to set.
	 *
	 * @return self
	 */
	public function setDescription( string $description ): self {
		$this->description = $description;

		return $this;
	}

	/**
	 * Sets the username to display with the embed.
	 *
	 * @param string $username The username to set.
	 *
	 * @return self
	 */
	public function setUsername( string $username ): self {
		$this->username = $username;

		return $this;
	}

	/**
	 * Sets the URL of the avatar to display with the embed.
	 *
	 * @param string $avatarUrl The URL of the avatar to set.
	 *
	 * @return self
	 */
	public function setAvatarUrl( string $avatarUrl ): self {
		$this->avatarUrl = $avatarUrl;

		return $this;
	}

	/**
	 * Builds and returns the JSON representation of the embed.
	 *
	 * @return string
	 */
	public function build(): string {
		$embed = [
			'embeds' => [
				[
					'color' => $this->color,
					'description' => $this->description,
				],
			],
			'username' => $this->username,
		];

		if ( $this->avatarUrl ) {
			$embed['avatar_url'] = $this->avatarUrl;
		}

		return json_encode( $embed );
	}
}
