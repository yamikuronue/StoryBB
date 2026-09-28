#!/usr/bin/env php
<?php

/**
 * Non-interactive StoryBB installer for Docker.
 *
 * Reads configuration from environment variables, writes Settings.php,
 * creates schema + seed data, and creates the administrator account.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @license 3-clause BSD (see accompanying LICENSE file)
 */

declare(strict_types=1);

use StoryBB\App;
use StoryBB\Container;
use StoryBB\Database\AdapterFactory;
use StoryBB\Database\Exception\ConnectionFailedException;
use StoryBB\Database\Exception\CouldNotSelectDatabaseException;
use StoryBB\Helper\Datetime as StoryBBDatetime;
use StoryBB\Model\Policy;
use StoryBB\Schema\Schema;

if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "This installer must be run from the command line.\n");
	exit(1);
}

define('STORYBB', 1);

$boarddir = getenv('STORYBB_BOARDDIR') ?: '/var/www/html';
$boarddir = rtrim($boarddir, '/');
chdir($boarddir);

require_once $boarddir . '/vendor/autoload.php';

$GLOBALS['current_sbb_version'] = App::SOFTWARE_VERSION;
$GLOBALS['db_script_version'] = '1-0';

$config = load_env_config();
$force_reconfig = env_flag('STORYBB_FORCE_RECONFIG');
$force_reinstall = env_flag('STORYBB_FORCE_REINSTALL');

$settings_path = $boarddir . '/Settings.php';
$already_configured = is_readable($settings_path);

if (!$already_configured || $force_reconfig || $force_reinstall)
{
	write_settings_file($boarddir, $config);
	echo "Wrote Settings.php\n";
}
else
{
	echo "Settings.php already present (set STORYBB_FORCE_RECONFIG=1 to rewrite from env)\n";
}

// Load settings into globals expected by StoryBB.
require $settings_path;

global $db_type, $db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_persist, $db_port;
global $boardurl, $language, $sourcedir, $cachedir, $cookiename, $smcFunc, $modSettings, $txt;

if (empty($sourcedir))
{
	$sourcedir = $boarddir . '/Sources';
}
if (empty($cachedir))
{
	$cachedir = $boarddir . '/cache';
}

require_once $sourcedir . '/Subs.php';
require_once $sourcedir . '/Subs-Auth.php';
require_once $sourcedir . '/Errors.php';
require_once $sourcedir . '/Logging.php';
require_once $sourcedir . '/Load.php';
require_once $sourcedir . '/Security.php';
require_once $sourcedir . '/ScheduledTasks.php';

$smcFunc = [];
$modSettings = ['disableQueryCheck' => true];

$db = connect_database($config);
$smcFunc['db'] = $db;

if ($force_reinstall)
{
	echo "STORYBB_FORCE_REINSTALL=1: dropping and recreating database {$config['db_name']}...\n";
	$db->query('', 'DROP DATABASE IF EXISTS `' . str_replace('`', '``', $config['db_name']) . '`', [
		'security_override' => true,
		'db_error_skip' => true,
	]);
	if (!$db->create_database($config['db_name']))
	{
		fail('Could not recreate database ' . $config['db_name']);
	}
}

App::start($boarddir, new \StoryBB\App\Installer);
$db = $smcFunc['db'];

if (is_installed($db, $config['db_prefix']) && !$force_reinstall)
{
	echo "StoryBB already installed; skipping schema/admin bootstrap.\n";
	exit(0);
}

$lang = $config['language'];
$lang_file = $boarddir . '/Languages/' . $lang . '/Install.php';
if (!is_readable($lang_file))
{
	fail("Language install file not found: {$lang_file}");
}
require $lang_file;

populate_database($boarddir, $db, $config, $txt);
create_admin_account($db, $config);
finalize_install($db, $config, $txt);

echo "StoryBB install completed successfully.\n";
exit(0);

/**
 * @return array<string, mixed>
 */
function load_env_config(): array
{
	$required = [
		'STORYBB_DB_SERVER',
		'STORYBB_DB_NAME',
		'STORYBB_DB_USER',
		'STORYBB_DB_PASSWD',
		'STORYBB_BOARDURL',
		'STORYBB_ADMIN_USERNAME',
		'STORYBB_ADMIN_PASSWORD',
		'STORYBB_ADMIN_EMAIL',
	];

	$missing = [];
	foreach ($required as $key)
	{
		$val = getenv($key);
		if ($val === false || $val === '')
		{
			$missing[] = $key;
		}
	}
	if ($missing)
	{
		fail('Missing required environment variables: ' . implode(', ', $missing));
	}

	$boardurl = rtrim((string) getenv('STORYBB_BOARDURL'), '/');
	if (!preg_match('~^https?://~i', $boardurl))
	{
		$boardurl = 'http://' . $boardurl;
	}

	$admin_user = (string) getenv('STORYBB_ADMIN_USERNAME');
	$admin_user = preg_replace('~[<>&"\'=\\\\]~', '', $admin_user);
	$admin_user = preg_replace('~[\t\n\r\x0B\0\xA0]+~', ' ', $admin_user);
	if ($admin_user === '' || strlen($admin_user) > 25)
	{
		fail('STORYBB_ADMIN_USERNAME must be 1-25 characters after sanitization.');
	}
	if (strlen((string) getenv('STORYBB_ADMIN_PASSWORD')) < 4)
	{
		fail('STORYBB_ADMIN_PASSWORD must be at least 4 characters.');
	}

	$email = (string) getenv('STORYBB_ADMIN_EMAIL');
	if (!filter_var($email, FILTER_VALIDATE_EMAIL))
	{
		fail('STORYBB_ADMIN_EMAIL is not a valid email address.');
	}

	$server_email = getenv('STORYBB_SERVER_EMAIL') ?: $email;
	if (!filter_var($server_email, FILTER_VALIDATE_EMAIL))
	{
		fail('STORYBB_SERVER_EMAIL is not a valid email address.');
	}

	$db_prefix = getenv('STORYBB_DB_PREFIX') ?: 'sbb_';
	$db_prefix = preg_replace('~[^A-Za-z0-9_$]~', '', $db_prefix);
	if ($db_prefix === '')
	{
		$db_prefix = 'sbb_';
	}

	$db_name = (string) getenv('STORYBB_DB_NAME');
	$secret = getenv('STORYBB_IMAGE_PROXY_SECRET');
	if ($secret === false || $secret === '')
	{
		$secret = bin2hex(random_bytes(10));
	}

	$port = (int) (getenv('STORYBB_DB_PORT') ?: 3306);

	return [
		'db_server' => (string) getenv('STORYBB_DB_SERVER'),
		'db_port' => $port,
		'db_name' => $db_name,
		'db_user' => (string) getenv('STORYBB_DB_USER'),
		'db_passwd' => (string) getenv('STORYBB_DB_PASSWD'),
		'db_prefix' => $db_prefix,
		'boardurl' => $boardurl,
		'forum_name' => getenv('STORYBB_FORUM_NAME') ?: 'StoryBB',
		'language' => getenv('STORYBB_LANGUAGE') ?: 'en-us',
		'admin_username' => $admin_user,
		'admin_password' => (string) getenv('STORYBB_ADMIN_PASSWORD'),
		'admin_email' => $email,
		'server_email' => (string) $server_email,
		'image_proxy_secret' => (string) $secret,
		'cookiename' => 'SBBCookie' . abs(crc32($db_name . preg_replace('~[^A-Za-z0-9_$]~', '', $db_prefix)) % 1000),
	];
}

function env_flag(string $name): bool
{
	$val = getenv($name);
	if ($val === false || $val === '')
	{
		return false;
	}
	return in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @param array<string, mixed> $config
 */
function write_settings_file(string $boarddir, array $config): void
{
	$template = $boarddir . '/docker/Settings.php.template';
	if (!is_readable($template))
	{
		fail("Settings template missing: {$template}");
	}

	$port_line = '';
	if (!empty($config['db_port']) && (int) $config['db_port'] !== 3306)
	{
		$port_line = '$db_port = ' . (int) $config['db_port'] . ';';
	}

	$replacements = [
		'{{LANGUAGE}}' => php_string($config['language']),
		'{{BOARDURL}}' => php_string($config['boardurl']),
		'{{COOKIENAME}}' => php_string($config['cookiename']),
		'{{DB_SERVER}}' => php_string($config['db_server']),
		'{{DB_NAME}}' => php_string($config['db_name']),
		'{{DB_USER}}' => php_string($config['db_user']),
		'{{DB_PASSWD}}' => php_string($config['db_passwd']),
		'{{DB_PREFIX}}' => php_string($config['db_prefix']),
		'{{IMAGE_PROXY_SECRET}}' => php_string($config['image_proxy_secret']),
		'{{DB_PORT_LINE}}' => $port_line,
		'{{BOARDDIR}}' => php_string($boarddir),
	];

	$contents = strtr(file_get_contents($template), $replacements);
	if (file_put_contents($boarddir . '/Settings.php', $contents) === false)
	{
		fail('Unable to write Settings.php');
	}
	@chmod($boarddir . '/Settings.php', 0640);
}

function php_string(string $value): string
{
	return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}

/**
 * @param array<string, mixed> $config
 * @return \StoryBB\Database\DatabaseAdapter
 */
function connect_database(array $config)
{
	try
	{
		$db = AdapterFactory::get_adapter('mysql');
	}
	catch (Throwable $e)
	{
		fail('Could not load MySQL adapter: ' . $e->getMessage());
	}

	$db->set_prefix($config['db_prefix']);
	$db->set_server(
		$config['db_server'],
		$config['db_name'],
		$config['db_user'],
		$config['db_passwd'],
		(int) $config['db_port']
	);

	try
	{
		$db->connect(['persist' => false]);
		return $db;
	}
	catch (CouldNotSelectDatabaseException $e)
	{
		// Database missing — connect without selecting and create it.
	}
	catch (ConnectionFailedException $e)
	{
		fail('Database connection failed: ' . $e->getMessage());
	}
	catch (Throwable $e)
	{
		// Older code paths may throw generic errors when the DB is missing.
		echo "Initial connect failed ({$e->getMessage()}); trying to create database...\n";
	}

	try
	{
		$db->connect(['persist' => false, 'dont_select_db' => true, 'non_fatal' => true]);
	}
	catch (Throwable $e)
	{
		fail('Database connection failed: ' . $e->getMessage());
	}

	if (!$db->connection_active())
	{
		fail('Database connection was not established.');
	}

	if (!$db->create_database($config['db_name']))
	{
		fail('Could not create or select database ' . $config['db_name'] . ' (does the user have CREATE privilege, or was MYSQL_DATABASE set in Compose?)');
	}

	return $db;
}

function is_installed($db, string $prefix): bool
{
	$result = $db->query('', '
		SELECT value
		FROM {db_prefix}settings
		WHERE variable = {string:name}
		LIMIT 1',
		[
			'name' => 'sbbVersion',
			'db_error_skip' => true,
		]
	);

	if ($result === false)
	{
		return false;
	}

	$row = $db->fetch_assoc($result);
	$db->free_result($result);

	return !empty($row['value']);
}

/**
 * @param array<string, mixed> $config
 * @param array<string, string> $txt
 */
function populate_database(string $boarddir, $db, array $config, array $txt): void
{
	global $modSettings;

	echo "Creating tables...\n";
	$exists = [];
	foreach (Schema::get_tables() as $table)
	{
		if (!$table->exists($db))
		{
			if (!$table->create($db))
			{
				fail('Failed creating table: ' . $table->get_table_name() . ' — ' . $db->error_message());
			}
		}
		else
		{
			$exists[] = $table->get_table_name();
		}
	}

	$attachdir = $boarddir . '/attachments';
	$dateformats = array_keys(StoryBBDatetime::list_dateformats());
	$default_time_format = $dateformats[0] ?? '%B %d, %Y, %I:%M:%S %p';

	$replaces = [
		'{$db_prefix}' => $config['db_prefix'],
		'{$language}' => $config['language'],
		'{$attachdir}' => json_encode([1 => $db->escape_string($attachdir)]),
		'{$boarddir}' => $db->escape_string($boarddir),
		'{$boardurl}' => $config['boardurl'],
		'{$databaseSession_enable}' => (ini_get('session.auto_start') != 1) ? '1' : '0',
		'{$sbb_version}' => $GLOBALS['current_sbb_version'],
		'{$current_time}' => (string) time(),
		'{$sched_task_offset}' => (string) (82800 + mt_rand(0, 86399)),
		'{$registration_method}' => '0',
		'{$default_time_format}' => $default_time_format,
		'{$default_forum_name}' => $db->escape_string($config['forum_name']),
	];

	foreach ($txt as $key => $value)
	{
		if (substr($key, 0, 8) === 'default_')
		{
			$replaces['{$' . $key . '}'] = $db->escape_string($value);
		}
	}
	if (isset($replaces['{$default_reserved_names}']))
	{
		$replaces['{$default_reserved_names}'] = strtr($replaces['{$default_reserved_names}'], ['\\\\n' => '\\n']);
	}

	$sql_file = $boarddir . '/other/install_' . $GLOBALS['db_script_version'] . '_mysql.sql';
	if (!is_readable($sql_file))
	{
		fail("Install SQL not found: {$sql_file}");
	}

	echo "Seeding database from " . basename($sql_file) . "...\n";
	$sql_lines = explode("\n", strtr(implode(' ', file($sql_file)), $replaces));

	$db->transaction('begin');
	$current_statement = '';
	foreach ($sql_lines as $count => $line)
	{
		if (substr(trim($line), 0, 1) !== '#')
		{
			$current_statement .= "\n" . rtrim($line);
		}

		if ($current_statement === '' || (preg_match('~;[\s]*$~s', $line) == 0 && $count != count($sql_lines)))
		{
			continue;
		}

		if (preg_match('~^\s*INSERT INTO ([^\s\n\r]+?)~', $current_statement, $match) != 0 && in_array($match[1], $exists, true))
		{
			$current_statement = '';
			continue;
		}

		if ($db->query('', $current_statement, ['security_override' => true, 'db_error_skip' => true]) === false)
		{
			if (!preg_match('~^\s*CREATE( UNIQUE)? INDEX ([^\n\r]+?)~', $current_statement, $match))
			{
				fail('SQL seed failed: ' . $db->error_message() . "\nStatement: " . substr(trim($current_statement), 0, 200));
			}
		}

		$current_statement = '';
		set_time_limit(60);
	}
	$db->transaction('commit');

	$container = Container::instance();
	$installer = $container->instantiate('StoryBB\\Helper\\Installer');
	$installer->upload_favicon();
	$installer->upload_smileys();
	$installer->add_standard_blocks();
	$installer->add_default_user_preferences();

	$newSettings = [
		['webmaster_email', $config['server_email']],
	];

	if (function_exists('date_default_timezone_set'))
	{
		$ini_tz = ini_get('date.timezone');
		$timezone_id = !empty($ini_tz) ? $ini_tz : 'UTC';
		if (!in_array($timezone_id, timezone_identifiers_list(), true))
		{
			$timezone_id = 'UTC';
		}
		date_default_timezone_set($timezone_id);
		$newSettings[] = ['default_timezone', $timezone_id];
	}

	$db->insert('replace',
		'{db_prefix}settings',
		['variable' => 'string-255', 'value' => 'string-65534'],
		$newSettings,
		['variable']
	);

	echo "Database populated.\n";
}

/**
 * @param array<string, mixed> $config
 */
function create_admin_account($db, array $config): void
{
	global $db_prefix;

	$request = $db->query('', '
		SELECT id_member
		FROM {db_prefix}members
		WHERE id_group = {int:admin_group} OR FIND_IN_SET({int:admin_group}, additional_groups) != 0
		LIMIT 1',
		[
			'db_error_skip' => true,
			'admin_group' => 1,
		]
	);
	if ($request !== false && $db->num_rows($request) != 0)
	{
		$db->free_result($request);
		echo "Administrator account already exists; skipping admin creation.\n";
		return;
	}
	if ($request !== false)
	{
		$db->free_result($request);
	}

	echo "Creating administrator account '{$config['admin_username']}'...\n";

	$salt = substr(md5((string) mt_rand()), 0, 4);
	$password_hash = hash_password($config['admin_username'], $config['admin_password']);

	$member_id = $db->insert('',
		$db_prefix . 'members',
		[
			'member_name' => 'string-25', 'real_name' => 'string-25', 'passwd' => 'string', 'email_address' => 'string',
			'id_group' => 'int', 'posts' => 'int', 'date_registered' => 'int',
			'password_salt' => 'string', 'lngfile' => 'string', 'avatar' => 'string',
			'member_ip' => 'inet', 'member_ip2' => 'inet', 'buddy_list' => 'string', 'pm_ignore_list' => 'string',
			'signature' => 'string', 'secret_question' => 'string',
			'additional_groups' => 'string', 'ignore_boards' => 'string',
			'policy_acceptance' => 'int',
		],
		[
			$config['admin_username'], $config['admin_username'], $password_hash, $config['admin_email'],
			1, 0, time(),
			$salt, '', '',
			'127.0.0.1', '127.0.0.1', '', '',
			'', '',
			'', '',
			Policy::POLICY_CURRENTLYACCEPTED,
		],
		['id_member'],
		1
	);

	if (empty($member_id))
	{
		fail('Failed to insert administrator member row.');
	}

	$character_id = $db->insert('',
		$db_prefix . 'characters',
		[
			'id_member' => 'int', 'character_name' => 'string', 'avatar' => 'string',
			'signature' => 'string', 'id_theme' => 'int', 'posts' => 'int',
			'date_created' => 'int', 'last_active' => 'int', 'is_main' => 'int',
			'main_char_group' => 'int', 'char_groups' => 'string', 'char_sheet' => 'int',
			'retired' => 'int',
		],
		[
			$member_id, $config['admin_username'], '',
			'', 0, 0,
			time(), 0, 1,
			0, '', 0,
			0,
		],
		['id_character'],
		1
	);

	$db->query('', '
		UPDATE {db_prefix}members
		SET current_character = {int:current_character}
		WHERE id_member = {int:id_member}',
		[
			'current_character' => $character_id,
			'id_member' => $member_id,
		]
	);

	$db->insert('ignore',
		'{db_prefix}log_activity',
		['date' => 'date', 'topics' => 'int', 'posts' => 'int', 'registers' => 'int'],
		[dateformat_ymd(time()), 1, 1, 1],
		['date']
	);
}

/**
 * @param array<string, mixed> $config
 * @param array<string, string> $txt
 */
function finalize_install($db, array $config, array $txt): void
{
	global $modSettings, $user_info, $sourcedir;

	$container = Container::instance();
	$container->inject('sitesettings', function () use ($container) {
		return $container->instantiate('StoryBB\\Helper\\SiteSettings');
	});

	try
	{
		$modSettings = $container->get('sitesettings')->get_all();
	}
	catch (Throwable $e)
	{
		echo "Warning: could not load site settings yet ({$e->getMessage()})\n";
		$modSettings = $modSettings ?? [];
	}

	updateStats('member');
	updateStats('message');
	updateStats('topic');

	$request = $db->query('', '
		SELECT id_msg
		FROM {db_prefix}messages
		WHERE id_msg = 1
			AND modified_time = 0
		LIMIT 1',
		['db_error_skip' => true]
	);
	if ($request !== false && $db->num_rows($request) > 0)
	{
		updateStats('subject', 1, htmlspecialchars($txt['default_topic_subject'] ?? 'Welcome to StoryBB!'));
	}
	if ($request !== false)
	{
		$db->free_result($request);
	}

	if (isset($modSettings['recycle_board']))
	{
		try
		{
			(new \StoryBB\Task\Schedulable\FetchStoryBBFiles)->execute();
		}
		catch (Throwable $e)
		{
			echo "Warning: FetchStoryBBFiles skipped ({$e->getMessage()})\n";
		}

		$user_info['ip'] = '127.0.0.1';
		$user_info['id'] = 0;
		try
		{
			logAction('install', ['version' => App::SOFTWARE_VERSION], 'admin');
		}
		catch (Throwable $e)
		{
			echo "Warning: could not write install log ({$e->getMessage()})\n";
		}
	}

	try
	{
		updateSettings([
			'bcrypt_hash_cost' => hash_benchmark(),
		]);
	}
	catch (Throwable $e)
	{
		echo "Warning: could not benchmark bcrypt cost ({$e->getMessage()})\n";
	}

	echo "Install finalization complete.\n";
}

function fail(string $message): void
{
	fwrite(STDERR, 'ERROR: ' . $message . "\n");
	exit(1);
}
