<?php

/**
 * CLI command to migrate a legacy (SMF-era "3.0 Alpha 1") StoryBB database to the current schema.
 *
 * @package StoryBB (storybb.org) - A roleplayer's forum software
 * @copyright 2026 StoryBB and individual contributors (see contributors.txt)
 * @license 3-clause BSD (see accompanying LICENSE file)
 *
 * @version 1.0 Alpha 1
 */

namespace StoryBB\Cli\Command\Migrate;

use StoryBB\App;
use StoryBB\Cli\Command as StoryBBCommand;
use StoryBB\Container;
use StoryBB\Dependency\Database as DatabaseDependency;
use StoryBB\Helper\Datetime as StoryBBDatetime;
use StoryBB\Model\Policy;
use StoryBB\Schema\Schema;
use StoryBB\Schema\Table;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Brings a database created by the 2017-2018 StoryBB builds (which still identified
 * themselves with smfVersion) up to the current schema and seeds the data the
 * current code expects. Columns and tables the current code no longer uses are
 * kept, never dropped.
 */
class Legacy extends Command implements StoryBBCommand
{
	use DatabaseDependency;

	/** @var bool $execute Whether to change the database or only report */
	protected $execute = false;

	/** @var OutputInterface $output */
	protected $output;

	/** @var string[] $errors Failed statements */
	protected $errors = [];

	public function configure()
	{
		$this->setName('migrate:legacy')
			->setDescription('Migrate a legacy smfVersion StoryBB database to the current schema.')
			->setHelp('Without --execute this only reports what would change. Back up the database before using --execute.')
			->addOption('execute', null, InputOption::VALUE_NONE, 'Apply the changes (default is a dry run)')
			->addOption('smileys-dir', null, InputOption::VALUE_REQUIRED, 'Folder containing the old smiley images, if they are not the StoryBB defaults');
	}

	public function execute(InputInterface $input, OutputInterface $output)
	{
		global $modSettings;

		$this->execute = (bool) $input->getOption('execute');
		$this->output = $output;
		$db = $this->db();

		// Raw DDL trips the legacy query checker; strict mode would reject clamping out-of-range legacy values.
		$modSettings['disableQueryCheck'] = true;
		$this->sql("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'", true);

		$versions = $this->versions();
		if (!empty($versions['sbbVersion']))
		{
			$output->writeln('Database already reports sbbVersion ' . $versions['sbbVersion'] . '; nothing to migrate.');
			return 0;
		}
		if (empty($versions['smfVersion']))
		{
			$output->writeln('<error>No smfVersion setting found; this does not look like a legacy StoryBB database.</error>');
			return 1;
		}

		$output->writeln('Legacy StoryBB database found (smfVersion ' . $versions['smfVersion'] . ').');
		$output->writeln($this->execute ? '<comment>Executing migration.</comment>' : '<comment>Dry run: no changes will be made. Re-run with --execute to apply.</comment>');

		$plan = $this->plan_schema();

		$this->heading('Clearing transient data');
		foreach (['sessions', 'log_online', 'log_scheduled_tasks'] as $table)
		{
			if ($this->table_exists($table))
			{
				$this->sql('TRUNCATE TABLE ' . $this->prefixed($table));
			}
		}

		$this->heading('Checking primary keys');
		$this->ensure_primary_keys();

		$this->heading('Converting tables to InnoDB / utf8mb4');
		$this->convert_tables();

		$this->heading('Updating schema');
		$this->report_negative_values($plan);
		$this->apply_schema($plan);

		if (!$this->execute)
		{
			$this->heading('Data migration (runs with --execute)');
			foreach ($this->data_steps() as $description)
			{
				$output->writeln('  - ' . $description);
			}
			$this->summary();
			return 0;
		}

		$this->heading('Seeding defaults from the install script');
		$this->seed_defaults();

		$this->heading('Migrating data');
		$this->migrate_data($input->getOption('smileys-dir'));

		$this->heading('Recording new version');
		$this->sql('DELETE FROM ' . $this->prefixed('settings') . " WHERE variable = 'smfVersion'");
		$this->sql('REPLACE INTO ' . $this->prefixed('settings') . " (variable, value) VALUES ('sbbVersion', '" . $db->escape_string(App::SOFTWARE_VERSION) . "')");

		$this->summary();
		return $this->errors ? 1 : 0;
	}

	protected function versions(): array
	{
		$db = $this->db();
		$result = $db->query('', '
			SELECT variable, value
			FROM {db_prefix}settings
			WHERE variable IN ({array_string:names})',
			[
				'names' => ['sbbVersion', 'smfVersion'],
				'db_error_skip' => true,
			]
		);

		$versions = [];
		if ($result === false)
		{
			return $versions;
		}
		while ($row = $db->fetch_assoc($result))
		{
			$versions[$row['variable']] = $row['value'];
		}
		$db->free_result($result);

		return $versions;
	}

	/**
	 * Works out, per schema table, what needs creating or altering.
	 */
	protected function plan_schema(): array
	{
		$db = $this->db();
		$plan = [];

		foreach (Schema::get_tables() as $table)
		{
			$name = $table->get_table_name();
			if (!$this->table_exists($name))
			{
				$plan[$name] = ['create' => $table];
				continue;
			}

			$existing = $db->get_table_structure('{db_prefix}' . $name);
			$raw_columns = $this->raw_columns($name);
			$src_columns = $existing->get_columns();
			$dest_columns = $table->get_columns();

			$changes = ['add' => [], 'change' => [], 'relax' => [], 'indexes' => [], 'unsigned' => []];

			foreach ($dest_columns as $column_name => $column)
			{
				$dest = $column->create_data($column_name);
				if (!isset($src_columns[$column_name]))
				{
					$changes['add'][$column_name] = $db->create_query_column($dest);
					continue;
				}

				$src = $src_columns[$column_name]->create_data($column_name);

				// Never shrink a column that is already wider than the schema asks for.
				if ($src['type'] == $dest['type'] && isset($src['size'], $dest['size']) && $src['size'] > $dest['size'])
				{
					$dest['size'] = $src['size'];
				}

				if ($this->column_differs($src, $dest))
				{
					$changes['change'][$column_name] = $db->create_query_column($dest);
					if (empty($src['unsigned']) && !empty($dest['unsigned']) && $this->is_integer($dest['type']))
					{
						$changes['unsigned'][] = $column_name;
					}
				}
			}

			// Columns the current code doesn't know about must not block its INSERTs.
			$primary_columns = $this->primary_columns($name);
			foreach ($raw_columns as $column_name => $raw)
			{
				if (isset($dest_columns[$column_name]) || in_array($column_name, $primary_columns) || $raw['Null'] == 'YES')
				{
					continue;
				}
				$changes['relax'][$column_name] = '`' . $column_name . '` ' . $raw['Type'] . ' NULL DEFAULT NULL';
			}

			$changes['indexes'] = $this->plan_indexes($name, $existing, $table);

			if (array_filter($changes))
			{
				$plan[$name] = ['alter' => $changes];
			}
		}

		return $plan;
	}

	protected function column_differs(array $src, array $dest): bool
	{
		if ($src['type'] != $dest['type'])
		{
			return true;
		}
		if ($this->is_integer($dest['type']) && empty($src['unsigned']) != empty($dest['unsigned']))
		{
			return true;
		}
		if (!empty($src['null']) != !empty($dest['null']))
		{
			return true;
		}
		if (isset($dest['size']) && (!isset($src['size']) || $src['size'] != $dest['size']) && !$this->is_integer($dest['type']))
		{
			return true;
		}
		if (!empty($dest['auto']) != !empty($src['auto']))
		{
			return true;
		}
		$src_default = isset($src['default']) ? (string) $src['default'] : null;
		$dest_default = isset($dest['default']) ? (string) $dest['default'] : null;
		if (!in_array($dest['type'], ['text', 'mediumtext', 'blob', 'mediumblob']) && $src_default !== $dest_default)
		{
			return true;
		}

		return false;
	}

	protected function is_integer(string $type): bool
	{
		return in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint']);
	}

	protected function plan_indexes(string $name, Table $existing, Table $table): array
	{
		$signature = function ($index) {
			$data = $index->create_data();
			return $data['type'] . '~' . strtolower(str_replace('`', '', implode('~', $data['columns'])));
		};

		$src = [];
		foreach ($existing->get_indexes() as $index)
		{
			$src[] = $signature($index);
		}

		$existing_names = $this->index_names($name);
		$statements = [];
		foreach ($table->get_indexes() as $index)
		{
			if (in_array($signature($index), $src))
			{
				continue;
			}

			$data = $index->create_data();
			$columns = implode(', ', $data['columns']);
			if ($data['type'] == 'primary')
			{
				$statements[] = (in_array('PRIMARY', $existing_names) ? 'DROP PRIMARY KEY, ' : '') . 'ADD PRIMARY KEY (' . $columns . ')';
				continue;
			}

			$index_name = substr(implode('_', array_map(function ($column) {
				return preg_replace('~[^a-z0-9_]~i', '', preg_replace('~\(.*\)~', '', $column));
			}, $data['columns'])), 0, 64);
			if (in_array($index_name, $existing_names))
			{
				$statements[] = 'DROP INDEX `' . $index_name . '`';
			}
			$keyword = ['unique' => 'UNIQUE', 'fulltext' => 'FULLTEXT', 'index' => 'INDEX'][$data['type']];
			$statements[] = 'ADD ' . $keyword . ' `' . $index_name . '` (' . $columns . ')';
		}

		return $statements;
	}

	protected function report_negative_values(array $plan)
	{
		foreach ($plan as $name => $item)
		{
			foreach ($item['alter']['unsigned'] ?? [] as $column)
			{
				$count = $this->scalar('SELECT COUNT(*) FROM ' . $this->prefixed($name) . ' WHERE `' . $column . '` < 0');
				if ($count > 0)
				{
					$this->output->writeln('  <comment>' . $name . '.' . $column . ': ' . $count . ' negative value(s) will become 0</comment>');
				}
			}
		}
	}

	protected function apply_schema(array $plan)
	{
		foreach ($plan as $name => $item)
		{
			if (isset($item['create']))
			{
				$this->output->writeln('  create ' . $name);
				if ($this->execute)
				{
					try
					{
						$item['create']->create($this->db());
					}
					catch (\Throwable $e)
					{
						$this->errors[] = 'create ' . $name . ': ' . $e->getMessage();
					}
				}
				continue;
			}

			$changes = $item['alter'];
			$clauses = [];
			foreach ($changes['add'] as $definition)
			{
				$clauses[] = 'ADD COLUMN ' . $definition;
			}
			foreach ($changes['change'] as $column_name => $definition)
			{
				$clauses[] = 'CHANGE COLUMN `' . $column_name . '` ' . $definition;
			}
			foreach ($changes['relax'] as $definition)
			{
				$clauses[] = 'MODIFY COLUMN ' . $definition;
			}
			$clauses = array_merge($clauses, $changes['indexes']);

			if ($clauses)
			{
				$this->sql('ALTER TABLE ' . $this->prefixed($name) . "\n\t" . implode(",\n\t", $clauses));
			}
		}
	}

	/**
	 * Managed MySQL (e.g. DigitalOcean) sets sql_require_primary_key, which blocks ALTERs on
	 * legacy tables that have no primary key. Lift it for this session if permitted, otherwise
	 * give those tables a surrogate key the application never reads.
	 */
	protected function ensure_primary_keys()
	{
		$db = $this->db();
		if ($this->scalar('SELECT @@SESSION.sql_require_primary_key') == 1)
		{
			$db->query('', 'SET SESSION sql_require_primary_key = 0', ['security_override' => true, 'db_error_skip' => true]);
		}
		if ($this->scalar('SELECT @@SESSION.sql_require_primary_key') != 1)
		{
			$this->output->writeln('  sql_require_primary_key is off for this session');
			return;
		}

		$result = $db->query('', '
			SELECT t.TABLE_NAME
			FROM information_schema.TABLES AS t
			WHERE t.TABLE_SCHEMA = DATABASE()
				AND t.TABLE_NAME LIKE {string:prefix}
				AND NOT EXISTS (
					SELECT 1 FROM information_schema.STATISTICS AS s
					WHERE s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME AND s.INDEX_NAME = {string:primary}
				)',
			[
				'prefix' => strtr($db->get_prefix(), ['_' => '\\_', '%' => '\\%']) . '%',
				'primary' => 'PRIMARY',
			]
		);
		$tables = [];
		while ($row = $db->fetch_assoc($result))
		{
			$tables[] = $row['TABLE_NAME'];
		}
		$db->free_result($result);

		foreach ($tables as $table)
		{
			$this->sql('ALTER TABLE `' . $table . '` ADD COLUMN `id_legacy_row` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
		}
		if (!$tables)
		{
			$this->output->writeln('  all tables have primary keys');
		}
	}

	protected function convert_tables()
	{
		$result = $this->db()->query('', '
			SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME LIKE {string:prefix}',
			[
				'prefix' => strtr($this->db()->get_prefix(), ['_' => '\\_', '%' => '\\%']) . '%',
			]
		);
		$tables = [];
		while ($row = $this->db()->fetch_assoc($result))
		{
			$tables[] = $row;
		}
		$this->db()->free_result($result);

		foreach ($tables as $row)
		{
			$options = [];
			if (strcasecmp($row['ENGINE'], 'InnoDB') != 0)
			{
				$options[] = 'ENGINE=InnoDB';
			}
			if ($row['TABLE_COLLATION'] != 'utf8mb4_unicode_ci')
			{
				$options[] = 'ROW_FORMAT=DYNAMIC';
				$options[] = 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}
			if ($options)
			{
				$this->sql('ALTER TABLE `' . $row['TABLE_NAME'] . '` ' . implode(', ', $options));
			}
		}
	}

	/**
	 * Runs the INSERTs from the current install script that the migrated database needs:
	 * any missing settings, the default theme definition, the current scheduled tasks,
	 * and default rows for tables that are still empty (i.e. new in this version).
	 */
	protected function seed_defaults()
	{
		$this->sql('DELETE FROM ' . $this->prefixed('scheduled_tasks'));

		$statements = $this->install_statements();
		$empty = [];
		foreach (array_unique(array_column($statements, 'table')) as $table)
		{
			if ($this->table_exists($table) && $this->scalar('SELECT COUNT(*) FROM ' . $this->prefixed($table)) == 0)
			{
				$empty[] = $table;
			}
		}

		foreach ($statements as $statement)
		{
			$table = $statement['table'];
			if ($table == 'settings' || (in_array($table, $empty) && $table != 'scheduled_tasks'))
			{
				$sql = preg_replace('~^\s*INSERT INTO~i', 'INSERT IGNORE INTO', $statement['sql']);
			}
			elseif ($table == 'themes')
			{
				$sql = preg_replace('~^\s*INSERT INTO~i', 'REPLACE INTO', $statement['sql']);
			}
			elseif ($table == 'scheduled_tasks')
			{
				$sql = $statement['sql'];
			}
			else
			{
				continue;
			}

			$this->output->writeln('  ' . $table);
			$this->sql($sql, false, false);
		}

		foreach (['theme_default' => '1', 'theme_guests' => '1', 'knownThemes' => '1'] as $variable => $value)
		{
			$this->sql('REPLACE INTO ' . $this->prefixed('settings') . " (variable, value) VALUES ('" . $variable . "', '" . $value . "')");
		}
	}

	protected function install_statements(): array
	{
		global $boardurl, $language, $txt;

		$db = $this->db();
		$boarddir = App::get_root_path();
		$lang = !empty($language) ? $language : 'en-us';

		if (!is_array($txt))
		{
			$txt = [];
		}
		require $boarddir . '/Languages/' . $lang . '/Install.php';

		$dateformats = array_keys(StoryBBDatetime::list_dateformats());
		$replaces = [
			'{$db_prefix}' => $db->get_prefix(),
			'{$language}' => $lang,
			'{$attachdir}' => json_encode([1 => $db->escape_string($boarddir . '/attachments')]),
			'{$boarddir}' => $db->escape_string($boarddir),
			'{$boardurl}' => $boardurl,
			'{$databaseSession_enable}' => '1',
			'{$sbb_version}' => App::SOFTWARE_VERSION,
			'{$current_time}' => (string) time(),
			'{$sched_task_offset}' => (string) (82800 + mt_rand(0, 86399)),
			'{$registration_method}' => '0',
			'{$default_time_format}' => $dateformats[0] ?? '%B %d, %Y, %I:%M:%S %p',
			'{$default_forum_name}' => $db->escape_string($txt['default_forum_name'] ?? 'StoryBB'),
		];
		foreach ($txt as $key => $value)
		{
			if (substr($key, 0, 8) === 'default_' && !isset($replaces['{$' . $key . '}']))
			{
				$replaces['{$' . $key . '}'] = $db->escape_string($value);
			}
		}
		if (isset($replaces['{$default_reserved_names}']))
		{
			$replaces['{$default_reserved_names}'] = strtr($replaces['{$default_reserved_names}'], ['\\\\n' => '\\n']);
		}

		$sql_lines = explode("\n", strtr(implode(' ', file($boarddir . '/other/install_1-0_mysql.sql')), $replaces));

		$statements = [];
		$current = '';
		foreach ($sql_lines as $count => $line)
		{
			if (substr(trim($line), 0, 1) !== '#')
			{
				$current .= "\n" . rtrim($line);
			}
			if ($current === '' || (preg_match('~;[\s]*$~s', $line) == 0 && $count != count($sql_lines)))
			{
				continue;
			}
			if (preg_match('~^\s*INSERT INTO ' . preg_quote($db->get_prefix(), '~') . '(\w+)~', $current, $match))
			{
				$statements[] = ['table' => $match[1], 'sql' => trim($current)];
			}
			$current = '';
		}

		return $statements;
	}

	protected function data_steps(): array
	{
		return [
			'seed missing settings, the natural theme, scheduled tasks and default policies from the install script',
			'set messages.id_creator from id_member',
			'mark all members as having accepted the current policies',
			'reset member languages that no longer exist to the forum default',
			'generate URL slugs for boards',
			'point members and boards using removed themes back to the default theme',
			'repoint attachment and avatar folders that do not exist in this install',
			'add the standard sidebar blocks, default user preferences and favicon if missing',
			'copy smiley images into the files store',
			'recalculate forum member, topic and post totals',
		];
	}

	protected function migrate_data(?string $smileys_dir)
	{
		$db = $this->db();
		$container = Container::instance();
		$installer = $container->instantiate('StoryBB\\Helper\\Installer');
		$boarddir = App::get_root_path();

		$this->step('messages.id_creator', 'UPDATE ' . $this->prefixed('messages') . ' SET id_creator = id_member WHERE id_creator = 0');
		$this->step('policy acceptance', 'UPDATE ' . $this->prefixed('members') . ' SET policy_acceptance = ' . Policy::POLICY_CURRENTLYACCEPTED);

		$languages = array_map('basename', glob($boarddir . '/Languages/*', GLOB_ONLYDIR));
		$this->step('member languages', 'UPDATE ' . $this->prefixed('members') . " SET lngfile = '' WHERE lngfile NOT IN ('" . implode("', '", array_map([$db, 'escape_string'], $languages)) . "')");

		$this->migrate_board_slugs();
		$this->migrate_themes();
		$this->migrate_folders();

		if ($this->scalar('SELECT COUNT(*) FROM ' . $this->prefixed('block_instances')) == 0)
		{
			$this->output->writeln('  standard blocks');
			$installer->add_standard_blocks();
		}
		if ($this->scalar('SELECT COUNT(*) FROM ' . $this->prefixed('user_preferences') . ' WHERE id_member = 0') == 0)
		{
			$this->output->writeln('  default user preferences');
			$installer->add_default_user_preferences();
		}
		if ($this->scalar('SELECT COUNT(*) FROM ' . $this->prefixed('files') . " WHERE handler = 'favicon'") == 0)
		{
			$this->output->writeln('  favicon');
			$installer->upload_favicon();
		}

		$this->migrate_smileys($smileys_dir);
		$this->refresh_stats();
	}

	/**
	 * Recalculates the forum totals shown on the board index, as updateStats() would.
	 */
	protected function refresh_stats()
	{
		$members = $this->prefixed('members');
		$boards = $this->prefixed('boards');
		$latest = (int) $this->scalar('SELECT MAX(id_member) FROM ' . $members . ' WHERE is_activated = 1');

		$stats = [
			'totalMembers' => (int) $this->scalar('SELECT COUNT(*) FROM ' . $members . ' WHERE is_activated = 1'),
			'latestMember' => $latest,
			'latestRealName' => (string) $this->scalar('SELECT real_name FROM ' . $members . ' WHERE id_member = ' . $latest),
			'unapprovedMembers' => (int) $this->scalar('SELECT COUNT(*) FROM ' . $members . ' WHERE is_activated IN (3, 4)'),
			'totalMessages' => (int) $this->scalar('SELECT SUM(num_posts + unapproved_posts) FROM ' . $boards . " WHERE redirect = ''"),
			'maxMsgID' => (int) $this->scalar('SELECT MAX(id_last_msg) FROM ' . $boards . " WHERE redirect = ''"),
			'totalTopics' => (int) $this->scalar('SELECT SUM(num_topics + unapproved_topics) FROM ' . $boards),
		];
		foreach ($stats as $variable => $value)
		{
			$this->set_setting($variable, (string) $value);
		}
		$this->output->writeln('  forum stats: ' . $stats['totalMembers'] . ' members, ' . $stats['totalTopics'] . ' topics, ' . $stats['totalMessages'] . ' posts');
	}

	protected function migrate_board_slugs()
	{
		$db = $this->db();
		$result = $db->query('', 'SELECT id_board, name, slug FROM {db_prefix}boards ORDER BY id_board', []);
		$boards = [];
		while ($row = $db->fetch_assoc($result))
		{
			$boards[] = $row;
		}
		$db->free_result($result);

		$used = [];
		foreach ($boards as $board)
		{
			if (!empty($board['slug']))
			{
				$used[] = strtolower($board['slug']);
			}
		}

		$count = 0;
		foreach ($boards as $board)
		{
			if (!empty($board['slug']))
			{
				continue;
			}
			$slug = strtolower(html_entity_decode($board['name'], ENT_QUOTES, 'UTF-8'));
			$slug = trim(preg_replace('~[^a-z0-9_]+~', '-', $slug), '-');
			if ($slug === '')
			{
				$slug = 'board-' . $board['id_board'];
			}
			$base = $slug;
			for ($i = 2; in_array($slug, $used); $i++)
			{
				$slug = $base . '-' . $i;
			}
			$used[] = $slug;

			$this->sql('UPDATE ' . $this->prefixed('boards') . " SET slug = '" . $db->escape_string($slug) . "' WHERE id_board = " . (int) $board['id_board'], true);
			$count++;
		}
		$this->output->writeln('  board slugs: ' . $count . ' generated');
	}

	protected function migrate_themes()
	{
		$db = $this->db();
		$result = $db->query('', '
			SELECT id_theme, value
			FROM {db_prefix}themes
			WHERE id_member = 0
				AND variable = {string:theme_dir}',
			[
				'theme_dir' => 'theme_dir',
			]
		);
		$removed = [];
		while ($row = $db->fetch_assoc($result))
		{
			if ($row['id_theme'] != 1 && !is_dir($row['value']))
			{
				$removed[] = (int) $row['id_theme'];
			}
		}
		$db->free_result($result);

		if ($removed)
		{
			$list = implode(', ', $removed);
			$this->step('removed themes (' . $list . ')', 'DELETE FROM ' . $this->prefixed('themes') . ' WHERE id_member = 0 AND id_theme IN (' . $list . ')');
			$this->step('members on removed themes', 'UPDATE ' . $this->prefixed('members') . ' SET id_theme = 0 WHERE id_theme IN (' . $list . ')');
			$this->step('boards on removed themes', 'UPDATE ' . $this->prefixed('boards') . ' SET id_theme = 0, override_theme = 0 WHERE id_theme IN (' . $list . ')');
		}
	}

	protected function migrate_folders()
	{
		global $boardurl;

		$db = $this->db();
		$boarddir = App::get_root_path();
		$settings = [];
		$result = $db->query('', '
			SELECT variable, value
			FROM {db_prefix}settings
			WHERE variable IN ({array_string:names})',
			[
				'names' => ['attachmentUploadDir', 'custom_avatar_dir', 'custom_avatar_url'],
			]
		);
		while ($row = $db->fetch_assoc($result))
		{
			$settings[$row['variable']] = $row['value'];
		}
		$db->free_result($result);

		// The current code always json-decodes this; single-folder legacy installs stored a plain path.
		$dirs = json_decode($settings['attachmentUploadDir'] ?? '', true);
		$changed = !is_array($dirs);
		if (!is_array($dirs))
		{
			$dirs = !empty($settings['attachmentUploadDir']) ? [1 => $settings['attachmentUploadDir']] : [];
		}

		$folders = array_keys($dirs);
		$result = $db->query('', 'SELECT DISTINCT id_folder FROM {db_prefix}attachments', []);
		while ($row = $db->fetch_assoc($result))
		{
			$folders[] = (int) $row['id_folder'];
		}
		$db->free_result($result);
		$folders = array_unique(array_merge([1], $folders));

		$new_dirs = [];
		foreach ($folders as $id)
		{
			if (isset($dirs[$id]) && is_dir($dirs[$id]))
			{
				$new_dirs[$id] = $dirs[$id];
				continue;
			}
			$new_dirs[$id] = $boarddir . '/attachments';
			$changed = true;
		}
		ksort($new_dirs);

		if ($changed)
		{
			$this->output->writeln('  attachment folders: ' . json_encode($new_dirs, JSON_UNESCAPED_SLASHES));
			$this->set_setting('attachmentUploadDir', json_encode($new_dirs));
			$this->set_setting('currentAttachmentUploadDir', (string) min(array_keys($new_dirs)));
		}

		if (empty($settings['custom_avatar_dir']) || !is_dir($settings['custom_avatar_dir']))
		{
			$this->output->writeln('  custom avatar folder -> ' . $boarddir . '/custom_avatar');
			$this->set_setting('custom_avatar_dir', $boarddir . '/custom_avatar');
			$this->set_setting('custom_avatar_url', rtrim($boardurl, '/') . '/custom_avatar');
		}
	}

	protected function migrate_smileys(?string $smileys_dir)
	{
		$db = $this->db();
		$filesystem = Container::instance()->get('filesystem');
		$search = array_filter([$smileys_dir, App::get_root_path() . '/install_resources/smileys']);
		$mimetypes = ['gif' => 'image/gif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];

		$result = $db->query('', '
			SELECT s.id_smiley, s.filename
			FROM {db_prefix}smileys AS s
				LEFT JOIN {db_prefix}files AS f ON (f.handler = {string:smiley} AND f.content_id = s.id_smiley)
			WHERE f.id IS NULL
			ORDER BY s.id_smiley',
			[
				'smiley' => 'smiley',
			]
		);
		$smileys = [];
		while ($row = $db->fetch_assoc($result))
		{
			$smileys[] = $row;
		}
		$db->free_result($result);

		$copied = 0;
		$missing = [];
		foreach ($smileys as $smiley)
		{
			$source = $this->find_smiley($search, $smiley['filename']);
			$ext = $source !== null ? strtolower(pathinfo($source, PATHINFO_EXTENSION)) : '';
			if ($source === null || !isset($mimetypes[$ext]))
			{
				$missing[] = $smiley['filename'];
				continue;
			}

			// The default set moved from .gif to .png under the same names.
			$filename = basename($source);
			if ($filename !== $smiley['filename'])
			{
				$this->sql('UPDATE ' . $this->prefixed('smileys') . " SET filename = '" . $db->escape_string($filename) . "' WHERE id_smiley = " . (int) $smiley['id_smiley'], true);
			}
			$filesystem->copy_physical_file($source, $filename, $mimetypes[$ext], 'smiley', (int) $smiley['id_smiley']);
			$copied++;
		}

		$this->output->writeln('  smileys: ' . $copied . ' copied');
		if ($missing)
		{
			$this->output->writeln('  <comment>smileys with no image found (' . count($missing) . '): ' . implode(', ', $missing) . '</comment>');
			$this->output->writeln('  <comment>Copy the old Smileys folder into the container and re-run with --smileys-dir to add them.</comment>');
		}
	}

	/**
	 * Finds a smiley image by exact name, then by the same name with any image extension.
	 */
	protected function find_smiley(array $search, string $filename): ?string
	{
		foreach ($search as $dir)
		{
			if (is_file($dir . '/' . $filename))
			{
				return $dir . '/' . $filename;
			}
		}

		$name = pathinfo($filename, PATHINFO_FILENAME);
		foreach ($search as $dir)
		{
			$matches = glob($dir . '/' . $name . '.{png,gif,jpg,jpeg,svg,webp}', GLOB_BRACE);
			if (!empty($matches))
			{
				return $matches[0];
			}
		}

		return null;
	}

	protected function set_setting(string $variable, string $value)
	{
		$db = $this->db();
		$this->sql('REPLACE INTO ' . $this->prefixed('settings') . " (variable, value) VALUES ('" . $db->escape_string($variable) . "', '" . $db->escape_string($value) . "')", true);
	}

	protected function step(string $description, string $sql)
	{
		if ($this->sql($sql, true) !== false)
		{
			$this->output->writeln('  ' . $description . ': ' . $this->db()->affected_rows() . ' row(s)');
		}
	}

	/**
	 * Runs (or, in a dry run, prints) a statement.
	 *
	 * @param bool $quiet Don't print the statement
	 * @param bool $dry_print In a dry run, print the statement
	 * @return mixed Query result, or false on error
	 */
	protected function sql(string $sql, bool $quiet = false, bool $dry_print = true)
	{
		if (!$this->execute)
		{
			if (!$quiet && $dry_print)
			{
				$this->output->writeln('  ' . str_replace("\n", "\n  ", $sql) . ';');
			}
			if (stripos(ltrim($sql), 'SET ') !== 0)
			{
				return true;
			}
		}
		elseif (!$quiet)
		{
			$this->output->writeln('  ' . strtok($sql, "\n") . (strpos($sql, "\n") !== false ? ' ...' : ''));
		}

		$result = $this->db()->query('', $sql, ['security_override' => true, 'db_error_skip' => true]);
		if ($result === false)
		{
			$error = $this->db()->error_message();
			$this->errors[] = strtok($sql, "\n") . ' -- ' . $error;
			$this->output->writeln('  <error>' . $error . '</error>');
		}

		return $result;
	}

	protected function scalar(string $sql)
	{
		$db = $this->db();
		$result = $db->query('', $sql, ['security_override' => true, 'db_error_skip' => true]);
		if ($result === false)
		{
			return null;
		}
		$row = $db->fetch_row($result);
		$db->free_result($result);
		return $row[0] ?? null;
	}

	protected function table_exists(string $name): bool
	{
		return $this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $this->db()->escape_string($this->db()->get_prefix() . $name) . "'") > 0;
	}

	protected function raw_columns(string $name): array
	{
		$db = $this->db();
		$result = $db->query('', 'SHOW COLUMNS FROM ' . $this->prefixed($name), ['security_override' => true]);
		$columns = [];
		while ($row = $db->fetch_assoc($result))
		{
			$columns[$row['Field']] = $row;
		}
		$db->free_result($result);
		return $columns;
	}

	protected function index_names(string $name): array
	{
		$db = $this->db();
		$result = $db->query('', 'SHOW INDEX FROM ' . $this->prefixed($name), ['security_override' => true]);
		$names = [];
		while ($row = $db->fetch_assoc($result))
		{
			$names[$row['Key_name']] = true;
		}
		$db->free_result($result);
		return array_keys($names);
	}

	protected function primary_columns(string $name): array
	{
		$db = $this->db();
		$result = $db->query('', 'SHOW INDEX FROM ' . $this->prefixed($name) . " WHERE Key_name = 'PRIMARY'", ['security_override' => true]);
		$columns = [];
		while ($row = $db->fetch_assoc($result))
		{
			$columns[] = $row['Column_name'];
		}
		$db->free_result($result);
		return $columns;
	}

	protected function prefixed(string $name): string
	{
		return '`' . $this->db()->get_prefix() . $name . '`';
	}

	protected function heading(string $text)
	{
		$this->output->writeln('');
		$this->output->writeln('<info>' . $text . '</info>');
	}

	protected function summary()
	{
		$this->output->writeln('');
		if ($this->errors)
		{
			$this->output->writeln('<error>' . count($this->errors) . ' statement(s) failed:</error>');
			foreach ($this->errors as $error)
			{
				$this->output->writeln('  ' . $error);
			}
			return;
		}
		$this->output->writeln($this->execute ? '<info>Migration complete.</info>' : '<info>Dry run complete.</info>');
	}
}
