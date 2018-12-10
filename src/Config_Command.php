<?php
use \WP_CLI\Utils;

/**
 * Generates and reads the wp-config.php file.
 */
class Config_Command extends WP_CLI_Command {

	private static function get_initial_locale() {
		include ABSPATH . '/wp-includes/version.php';

		// @codingStandardsIgnoreStart
		if ( isset( $wp_local_package ) )
			return $wp_local_package;
		// @codingStandardsIgnoreEnd

		return '';
	}

	/**
	 * Generates a wp-config.php file.
	 *
	 * Creates a new wp-config.php with database constants, and verifies that
	 * the database constants are correct.
	 *
	 * ## OPTIONS
	 *
	 * --dbname=<dbname>
	 * : Set the database name.
	 *
	 * --dbuser=<dbuser>
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database user password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host.
	 * ---
	 * default: localhost
	 * ---
	 *
	 * [--dbprefix=<dbprefix>]
	 * : Set the database table prefix.
	 * ---
	 * default: wp_
	 * ---
	 *
	 * [--dbcharset=<dbcharset>]
	 * : Set the database charset.
	 * ---
	 * default: utf8
	 * ---
	 *
	 * [--dbcollate=<dbcollate>]
	 * : Set the database collation.
	 * ---
	 * default:
	 * ---
	 *
	 * [--locale=<locale>]
	 * : Set the WPLANG constant. Defaults to $wp_local_package variable.
	 *
	 * [--extra-php]
	 * : If set, the command copies additional PHP code into wp-config.php from STDIN.
	 *
	 * [--skip-salts]
	 * : If set, keys and salts won't be generated, but should instead be passed via `--extra-php`.
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * [--force]
	 * : Overwrites existing files, if present.
	 *
	 * ## EXAMPLES
	 *
	 *     # Standard wp-config.php file
	 *     $ wp config create --dbname=testing --dbuser=wp --dbpass=securepswd --locale=ro_RO
	 *     Success: Generated 'wp-config.php' file.
	 *
	 *     # Enable WP_DEBUG and WP_DEBUG_LOG
	 *     $ wp config create --dbname=testing --dbuser=wp --dbpass=securepswd --extra-php <<PHP
	 *     define( 'WP_DEBUG', true );
	 *     define( 'WP_DEBUG_LOG', true );
	 *     PHP
	 *     Success: Generated 'wp-config.php' file.
	 *
	 *     # Avoid disclosing password to bash history by reading from password.txt
	 *     # Using --prompt=dbpass will prompt for the 'dbpass' argument
	 *     $ wp config create --dbname=testing --dbuser=wp --prompt=dbpass < password.txt
	 *     Success: Generated 'wp-config.php' file.
	 */
	public function create( $_, $assoc_args ) {
		global $wp_version;
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'force' ) && Utils\locate_wp_config() ) {
			WP_CLI::error( "The 'wp-config.php' file already exists." );
		}

		$versions_path = ABSPATH . 'wp-includes/version.php';
		include $versions_path;

		$defaults = array(
			'dbhost' => 'localhost',
			'dbpass' => '',
			'dbprefix' => 'wp_',
			'dbcharset' => 'utf8',
			'dbcollate' => '',
			'locale' => self::get_initial_locale()
		);
		$assoc_args = array_merge( $defaults, $assoc_args );

		if ( preg_match( '|[^a-z0-9_]|i', $assoc_args['dbprefix'] ) )
			WP_CLI::error( '--dbprefix can only contain numbers, letters, and underscores.' );

		// Check DB connection
		if ( ! Utils\get_flag_value( $assoc_args, 'skip-check' ) ) {
			Utils\run_mysql_command( '/usr/bin/env mysql --no-defaults', array(
				'execute' => ';',
				'host' => $assoc_args['dbhost'],
				'user' => $assoc_args['dbuser'],
				'pass' => $assoc_args['dbpass'],
			) );
		}

		if ( Utils\get_flag_value( $assoc_args, 'extra-php' ) === true ) {
			$assoc_args['extra-php'] = file_get_contents( 'php://stdin' );
		}

		if ( ! Utils\get_flag_value( $assoc_args, 'skip-salts' ) ) {
			try {
				$assoc_args['keys-and-salts'] = true;
				$assoc_args['auth-key'] = self::unique_key();
				$assoc_args['secure-auth-key'] = self::unique_key();
				$assoc_args['logged-in-key'] = self::unique_key();
				$assoc_args['nonce-key'] = self::unique_key();
				$assoc_args['auth-salt'] = self::unique_key();
				$assoc_args['secure-auth-salt'] = self::unique_key();
				$assoc_args['logged-in-salt'] = self::unique_key();
				$assoc_args['nonce-salt'] = self::unique_key();
				$assoc_args['wp-cache-key-salt'] = self::unique_key();
			} catch ( Exception $e ) {
				$assoc_args['keys-and-salts'] = false;
				$assoc_args['keys-and-salts-alt'] = self::_read(
					'https://api.wordpress.org/secret-key/1.1/salt/' );
			}
		}

		if ( Utils\wp_version_compare( '4.0', '<' ) ) {
			$assoc_args['add-wplang'] = true;
		} else {
			$assoc_args['add-wplang'] = false;
		}

		$command_root = Utils\phar_safe_path( dirname( __DIR__ ) );
		$out = Utils\mustache_render( $command_root . '/templates/wp-config.mustache', $assoc_args );

		$bytes_written = file_put_contents( ABSPATH . 'wp-config.php', $out );
		if ( ! $bytes_written ) {
			WP_CLI::error( "Could not create new 'wp-config.php' file." );
		} else {
			WP_CLI::success( "Generated 'wp-config.php' file." );
		}
	}

	/**
	 * Launches system editor to edit the wp-config.php file.
	 *
	 * ## EXAMPLES
	 *
	 *     # Launch system editor to edit wp-config.php file
	 *     $ wp config edit
	 *
	 *     # Edit wp-config.php file in a specific editor
	 *     $ EDITOR=vim wp config edit
	 *
	 * @when before_wp_load
	 */
	public function edit() {
		$config_path = $this->get_config_path();
		$contents = file_get_contents( $config_path );
		$r = Utils\launch_editor_for_input( $contents, 'wp-config.php', 'php' );
		if ( $r === false ) {
			WP_CLI::warning( 'No changes made to wp-config.php.', 'Aborted' );
		} else {
			file_put_contents( $config_path, $r );
		}
	}

	/**
	 * Gets the path to wp-config.php file.
	 *
	 * ## EXAMPLES
	 *
	 *     # Get wp-config.php file path
	 *     $ wp config path
	 *     /home/person/htdocs/project/wp-config.php
	 *
	 * @when before_wp_load
	 */
	public function path() {
		WP_CLI::line( $this->get_config_path() );
	}

	/**
	 * Lists variables, constants, and file includes defined in wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * [<filter>...]
	 * : Name or partial name to filter the list by.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields. Defaults to all fields.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--strict]
	 * : Enforce strict matching when a filter is provided.
	 *
	 * ## EXAMPLES
	 *
	 *     # List constants and variables defined in wp-config.php file.
	 *     $ wp config list
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | key              | value                                                            | type     |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | table_prefix     | wp_                                                              | variable |
	 *     | DB_NAME          | wp_cli_test                                                      | constant |
	 *     | DB_USER          | root                                                             | constant |
	 *     | DB_PASSWORD      | root                                                             | constant |
	 *     | AUTH_KEY         | r6+@shP1yO&$)1gdu.hl[/j;7Zrvmt~o;#WxSsa0mlQOi24j2cR,7i+QM/#7S:o^ | constant |
	 *     | SECURE_AUTH_KEY  | iO-z!_m--YH$Tx2tf/&V,YW*13Z_HiRLqi)d?$o-tMdY+82pK$`T.NYW~iTLW;xp | constant |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *
	 *     # List only database user and password from wp-config.php file.
	 *     $ wp config list DB_USER DB_PASSWORD --strict
	 *     +------------------+-------+----------+
	 *     | key              | value | type     |
	 *     +------------------+-------+----------+
	 *     | DB_USER          | root  | constant |
	 *     | DB_PASSWORD      | root  | constant |
	 *     +------------------+-------+----------+
	 *
	 *     # List all salts from wp-config.php file.
	 *     $ wp config list _SALT
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | key              | value                                                            | type     |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *     | AUTH_SALT        | n:]Xditk+_7>Qi=>BmtZHiH-6/Ecrvl(V5ceeGP:{>?;BT^=[B3-0>,~F5z$(+Q$ | constant |
	 *     | SECURE_AUTH_SALT | ?Z/p|XhDw3w}?c.z%|+BAr|(Iv*H%%U+Du&kKR y?cJOYyRVRBeB[2zF-`(>+LCC | constant |
	 *     | LOGGED_IN_SALT   | +$@(1{b~Z~s}Cs>8Y]6[m6~TnoCDpE>O%e75u}&6kUH!>q:7uM4lxbB6[1pa_X,q | constant |
	 *     | NONCE_SALT       | _x+F li|QL?0OSQns1_JZ{|Ix3Jleox-71km/gifnyz8kmo=w-;@AE8W,(fP<N}2 | constant |
	 *     +------------------+------------------------------------------------------------------+----------+
	 *
	 * @when before_wp_load
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$path = $this->get_config_path();

		$strict = Utils\get_flag_value( $assoc_args, 'strict' );
		if ( $strict && empty( $args ) ) {
			WP_CLI::error( 'The --strict option can only be used in combination with a filter.' );
		}

		$default_fields = array(
			'name',
			'value',
			'type',
		);

		$defaults = array(
			'fields' => implode( ',', $default_fields ),
			'format' => 'table',
		);

		$assoc_args = array_merge( $defaults, $assoc_args );

		$values = self::get_wp_config_vars();

		if ( ! empty( $args ) ) {
			$values = $this->filter_values( $values, $args, $strict );
		}

		if ( empty( $values ) ) {
			WP_CLI::error( "No matching entries found in 'wp-config.php'." );
		}

		Utils\format_items( $assoc_args['format'], $values, $assoc_args['fields'] );
	}

	/**
	 * Gets the value of a specific constant or variable defined in wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * [--type=<type>]
	 * : Type of config value to retrieve. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * [--format=<format>]
	 * : Get value in a particular format.
	 * ---
	 * default: var_export
	 * options:
	 *   - var_export
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Get the table_prefix as defined in wp-config.php file.
	 *     $ wp config get table_prefix
	 *     wp_
	 *
	 * @when before_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$path = $this->get_config_path();

		list( $name ) = $args;
		$type = Utils\get_flag_value( $assoc_args, 'type' );

		$value = $this->return_value( $name, $type, self::get_wp_config_vars() );
		WP_CLI::print_value( $value, $assoc_args );
	}

	/**
	 * Get the array of wp-config.php constants and variables.
	 *
	 * @return array
	 */
	private static function get_wp_config_vars() {
		$wp_cli_original_defined_constants = get_defined_constants();
		$wp_cli_original_defined_vars      = get_defined_vars();
		$wp_cli_original_includes          = get_included_files();

		eval( WP_CLI::get_runner()->get_wp_config_code() );

		$wp_config_vars      = self::get_wp_config_diff( get_defined_vars(), $wp_cli_original_defined_vars, 'variable', array( 'wp_cli_original_defined_vars' ) );
		$wp_config_constants = self::get_wp_config_diff( get_defined_constants(), $wp_cli_original_defined_constants, 'constant' );

		foreach ( $wp_config_vars as $name => $value ) {
			if ( 'wp_cli_original_includes' === $value['name'] ) {
				$name_backup = $name;
				break;
			}
		}

		unset( $wp_config_vars[ $name_backup ] );
		$wp_config_vars           = array_values( $wp_config_vars );
		$wp_config_includes       = array_diff( get_included_files(), $wp_cli_original_includes );
		$wp_config_includes_array = array();

		foreach ( $wp_config_includes as $name => $value ) {
			$wp_config_includes_array[] = array(
				'name'   => basename( $value ),
				'value' => $value,
				'type'  => 'includes',
			);
		}

		return array_merge( $wp_config_vars, $wp_config_constants, $wp_config_includes_array );
	}

	/**
	 * Sets the value of a specific constant or variable defined in wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * <value>
	 * : Value to set the wp-config.php constant or variable to.
	 *
	 * [--add]
	 * : Add the value if it doesn't exist yet.
	 * This is the default behavior, override with --no-add.
	 *
	 * [--raw]
	 * : Place the value into the wp-config.php file as is, instead of as a quoted string.
	 *
	 * [--anchor=<anchor>]
	 * : Anchor string where additions of new values are anchored around.
	 * Defaults to "/* That's all, stop editing!".
	 *
	 * [--placement=<placement>]
	 * : Where to place the new values in relation to the anchor string.
	 * ---
	 * default: 'before'
	 * options:
	 *   - before
	 *   - after
	 * ---
	 *
	 * [--separator=<separator>]
	 * : Separator string to put between an added value and its anchor string.
	 * The following escape sequences will be recognized and properly interpreted: '\n' => newline, '\r' => carriage return, '\t' => tab.
	 * Defaults to a single EOL ("\n" on *nix and "\r\n" on Windows).
	 *
	 * [--type=<type>]
	 * : Type of the config value to set. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Set the WP_DEBUG constant to true.
	 *     $ wp config set WP_DEBUG true --raw
	 *
	 * @when before_wp_load
	 */
	public function set( $args, $assoc_args ) {
		$path = $this->get_config_path();

		list( $name, $value ) = $args;
		$type = Utils\get_flag_value( $assoc_args, 'type' );

		$options = array();

		$option_flags = array(
			'raw'       => false,
			'add'       => true,
			'anchor'    => null,
			'placement' => null,
			'separator' => null,
		);

		foreach ( $option_flags as $option => $default ) {
			$option_value = Utils\get_flag_value( $assoc_args, $option, $default );
			if ( null !== $option_value ) {
				$options[ $option ] = $option_value;
				if ( 'separator' === $option ) {
					$options['separator'] = $this->parse_separator( $options['separator'] );
				}
			}
		}

		$adding = false;
		try {
			$config_transformer = new WPConfigTransformer( $path );

			switch ( $type ) {
				case 'all':
					$has_constant = $config_transformer->exists( 'constant', $name );
					$has_variable = $config_transformer->exists( 'variable', $name );
					if ( $has_constant && $has_variable ) {
						WP_CLI::error( "Found both a constant and a variable '{$name}' in the 'wp-config.php' file. Use --type=<type> to disambiguate." );
					}
					if ( ! $has_constant && ! $has_variable ) {
						$message = "The constant or variable '{$name}' is not defined in the 'wp-config.php' file.";
						if ( $options['add'] ) {
							$message .= ' Specify an explicit --type=<type> to add.';
						}
						WP_CLI::error( $message );
					} else {
						$type = $has_constant ? 'constant' : 'variable';
					}
					break;
				case 'constant':
				case 'variable':
					if ( ! $config_transformer->exists( $type, $name ) ) {
						if ( ! $options['add'] ) {
							WP_CLI::error( "The {$type} '{$name}' is not defined in the 'wp-config.php' file." );
						}
						$adding = true;
					}
			}

			$config_transformer->update( $type, $name, $value, $options );

		} catch ( Exception $exception ) {
			WP_CLI::error( "Could not process the 'wp-config.php' transformation.\nReason: " . $exception->getMessage() );
		}

		$raw  = $options['raw'] ? 'raw ' : '';
		if ( $adding ) {
			$message = "Added the {$type} '{$name}' to the 'wp-config.php' file with the {$raw}value '{$value}'.";
		} else {
			$message = "Updated the {$type} '{$name}' in the 'wp-config.php' file with the {$raw}value '{$value}'.";
		}

		WP_CLI::success( $message );
	}

	/**
	 * Deletes a specific constant or variable from the wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * [--type=<type>]
	 * : Type of the config value to delete. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete the COOKIE_DOMAIN constant from the wp-config.php file.
	 *     $ wp config delete COOKIE_DOMAIN
	 *
	 * @when before_wp_load
	 */
	public function delete( $args, $assoc_args ) {
		$path = $this->get_config_path();

		list( $name ) = $args;
		$type = Utils\get_flag_value( $assoc_args, 'type' );

		try {
			$config_transformer = new WPConfigTransformer( $path );

			switch ( $type ) {
				case 'all':
					$has_constant = $config_transformer->exists( 'constant', $name );
					$has_variable = $config_transformer->exists( 'variable', $name );
					if ( $has_constant && $has_variable ) {
						WP_CLI::error( "Found both a constant and a variable '{$name}' in the 'wp-config.php' file. Use --type=<type> to disambiguate." );
					}
					if ( ! $has_constant && ! $has_variable ) {
						WP_CLI::error( "The constant or variable '{$name}' is not defined in the 'wp-config.php' file." );
					} else {
						$type = $has_constant ? 'constant' : 'variable';
					}
					break;
				case 'constant':
				case 'variable':
					if ( ! $config_transformer->exists( $type, $name ) ) {
						WP_CLI::error( "The {$type} '{$name}' is not defined in the 'wp-config.php' file." );
					}
			}

			$config_transformer->remove( $type, $name );

		} catch ( Exception $exception ) {
			WP_CLI::error( "Could not process the 'wp-config.php' transformation.\nReason: " . $exception->getMessage() );
		}

		WP_CLI::success( "Deleted the {$type} '{$name}' from the 'wp-config.php' file." );
	}

	/**
	 * Checks whether a specific constant or variable exists in the wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name of the wp-config.php constant or variable.
	 *
	 * [--type=<type>]
	 * : Type of the config value to set. Defaults to 'all'.
	 * ---
	 * default: all
	 * options:
	 *   - constant
	 *   - variable
	 *   - all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Check whether the DB_PASSWORD constant exists in the wp-config.php file.
	 *     $ wp config has DB_PASSWORD
	 *
	 * @when before_wp_load
	 */
	public function has( $args, $assoc_args ) {
		$path = $this->get_config_path();

		list( $name ) = $args;
		$type = Utils\get_flag_value( $assoc_args, 'type' );

		try {
			$config_transformer = new WPConfigTransformer( $path );

			switch ( $type ) {
				case 'all':
					$has_constant = $config_transformer->exists( 'constant', $name );
					$has_variable = $config_transformer->exists( 'variable', $name );
					if ( $has_constant && $has_variable ) {
						WP_CLI::error( "Found both a constant and a variable '{$name}' in the 'wp-config.php' file. Use --type=<type> to disambiguate." );
					}
					if ( ! $has_constant && ! $has_variable ) {
						WP_CLI::halt( 1 );
					} else {
						WP_CLI::halt( 0 );
					}
					break;
				case 'constant':
				case 'variable':
					if ( ! $config_transformer->exists( $type, $name ) ) {
						WP_CLI::halt( 1 );
					}
					WP_CLI::halt( 0 );
			}

		} catch ( Exception $exception ) {
			WP_CLI::error( "Could not process the 'wp-config.php' transformation.\nReason: " . $exception->getMessage() );
		}
	}

	/**
	 * Refreshes the salts defined in the wp-config.php file.
	 *
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *
	 *     # Get new salts for your wp-config.php file
	 *     $ wp config shuffle-salts
	 *     Success: Shuffled the salt keys.
	 *
	 * @subcommand shuffle-salts
	 * @when before_wp_load
	 */
	public function shuffle_salts( $args, $assoc_args ) {

		$constant_list = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT'
		);

		try {
			foreach ( $constant_list as $key ) {
				$secret_keys[ $key ] = trim( self::unique_key() );
			}
		} catch ( Exception $ex ) {

			$remote_salts = self::_read( 'https://api.wordpress.org/secret-key/1.1/salt/' );
			$remote_salts = explode( "\n", $remote_salts );
			foreach ( $remote_salts as $k => $salt ) {
				if ( ! empty( $salt ) ) {
					$key = $constant_list[ $k ];
					$secret_keys[ $key ] = trim( substr( $salt, 28, 64 ) );
				}
			}

		}

		$path = $this->get_config_path();

		try {
			$config_transformer = new WPConfigTransformer( $path );
			foreach ( $secret_keys as $constant => $key ) {
				$config_transformer->update( 'constant', $constant, (string) $key );
			}
		} catch ( Exception $exception ) {
			WP_CLI::error( "Could not process the 'wp-config.php' transformation.\nReason: " . $exception->getMessage() );
		}

		WP_CLI::success( 'Shuffled the salt keys.' );

	}

	/**
	 * Filters wp-config.php file configurations.
	 *
	 * @param array $list
	 * @param array $previous_list
	 * @param string $type
	 * @param array $exclude_list
	 * @return array
	 */
	private static function get_wp_config_diff( $list, $previous_list, $type, $exclude_list = array() ) {
		$result = array();
		foreach ( $list as $name => $val ) {
			if ( array_key_exists( $name, $previous_list ) || in_array( $name, $exclude_list ) ) {
				continue;
			}
			$out = array();
			$out['name'] = $name;
			$out['value'] = $val;
			$out['type'] = $type;
			$result[] = $out;
		}
		return $result;
	}

	private static function _read( $url ) {
		$headers = array('Accept' => 'application/json');
		$response = Utils\http_request( 'GET', $url, null, $headers, array( 'timeout' => 30 ) );
		if ( 200 === $response->status_code ) {
			return $response->body;
		} else {
			WP_CLI::error( "Couldn't fetch response from {$url} (HTTP code {$response->status_code})." );
		}
	}

	/**
	 * Prints the value of a constant or variable defined in the wp-config.php file.
	 *
	 * If the constant or variable is not defined in the wp-config.php file then an error will be returned.
	 *
	 * @param string $name
	 * @param string $type
	 * @param array $values
	 *
	 * @return string The value of the requested constant or variable as defined in the wp-config.php file; if the
	 *                requested constant or variable is not defined then the function will print an error and exit.
	 */
	private function return_value( $name, $type, $values ) {
		$results = array();
		foreach ( $values as $value ) {
			if ( $name === $value['name'] && ( $type === 'all' || $type === $value['type'] ) ) {
				$results[] = $value;
			}
		}

		if ( count( $results ) > 1 ) {
			WP_CLI::error( "Found both a constant and a variable '{$name}' in the 'wp-config.php' file. Use --type=<type> to disambiguate." );
		}

		if ( ! empty( $results ) ) {
			return $results[0]['value'];
		}

		$type = $type === 'all' ? 'constant or variable' : $type;
		$names = array_column( $values, 'name' );
		$candidate = Utils\get_suggestion( $name, $names );

		if ( ! empty( $candidate ) && $candidate !== $name ) {
			WP_CLI::error( "The {$type} '{$name}' is not defined in the 'wp-config.php' file.\nDid you mean '{$candidate}'?" );
		}

		WP_CLI::error( "The {$type} '{$name}' is not defined in the 'wp-config.php' file." );
	}

	/**
	 * Generates a unique key/salt for the wp-config.php file.
	 *
	 * @throws Exception
	 *
	 * @return string
	 */
	private static function unique_key() {
		if ( ! function_exists( 'random_int' ) ) {
			throw new Exception( "'random_int' does not exist" );
		}

		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
		$key = '';

		for ( $i = 0; $i < 64; $i++ ) {
			$key .= substr( $chars, random_int( 0, strlen( $chars ) - 1 ), 1 );
		}

		return $key;
	}

	/**
	 * Filters the values based on a provider filter key.
	 *
	 * @param array $values
	 * @param array $filters
	 * @param bool $strict
	 *
	 * @return array
	 */
	private function filter_values( $values, $filters, $strict ) {
		$result = array();

		foreach ( $values as $value ) {
			foreach ( $filters as $filter ) {
				if ( $strict && $filter !== $value['name'] ) {
					continue;
				}

				if ( false === strpos( $value['name'], $filter ) ) {
					continue;
				}

				$result[] = $value;
			}
		}

		return $result;
	}

	/**
	 * Gets the path to the wp-config.php file or gives a helpful error if none found.
	 *
	 * @return string Path to wp-config.php file.
	 */
	private function get_config_path() {
		$path = Utils\locate_wp_config();
		if ( ! $path ) {
			WP_CLI::error( "'wp-config.php' not found.\nEither create one manually or use `wp config create`." );
		}
		return $path;
	}

	/**
	 * Parses the separator argument, to allow for special character handling.
	 *
	 * Does the following transformations:
	 * - '\n' => "\n" (newline)
	 * - '\r' => "\r" (carriage return)
	 * - '\t' => "\t" (tab)
	 *
	 * @param string $separator Separator string to parse.
	 *
	 * @return mixed Parsed separator string.
	 */
	private function parse_separator( $separator ) {
		$separator = str_replace(
			array( '\n', '\r', '\t' ),
			array( "\n", "\r", "\t" ),
			$separator
		);

		return $separator;
	}
}
