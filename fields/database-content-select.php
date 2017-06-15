<?php
	if (!defined('ABSPATH')) {
		exit;
	}
		
	if (!class_exists('ACFFieldDatabaseContentSelect')) {
				
		class ACFFieldDatabaseContentSelect extends acf_field {
			
			function __construct($settings) {
				$this->name = 'database_content_select';
				$this->label = __('Database Content Select', 'Database Content Select');
				$this->category = 'relational';
				$this->defaults = array();
				$this->l10n = array();
				$this->settings = $settings;
				
				parent::__construct();	
			}
			
			function render_field_settings($field) {
				global $wpdb;
				
				// Get a list of tables
				$results = $wpdb->get_results("SELECT table_name AS `table` FROM information_schema.tables WHERE table_schema = DATABASE()");
				$tables = array("" => "");

				foreach ($results as $result) {
					$tables[$result->table] = $result->table;
				}
				
				acf_render_field_setting($field, array(
					'label'			=> __('Table'),
					'instructions'	=> __('Choose the table to pull data from.'),
					'type'			=> 'select',
					'name'			=> 'table',
					'choices'		=> $tables
				));
			
				// Get a list of columns if we already have a table set
				$columns = array(array("" => ""));

				if ($field["table"]) {
					$table_data = ACFDatabaseContentFields::describeTable($field["table"]);
					
					foreach ($table_data["columns"] as $id => $column) {
						$columns[$id] = $id;
					}
				}

				// Render for ID and Descriptor
				acf_render_field_setting($field, array(
					'label'			=> __('ID Column'),
					'instructions'	=> __('Save Table first to populate this select.'),
					'type'			=> 'select',
					'name'			=> 'id_column',
					'choices'		=> $columns
				));

				acf_render_field_setting($field, array(
					'label'			=> __('Title Column'),
					'instructions'	=> __('Save Table first to populate this select.'),
					'type'			=> 'select',
					'name'			=> 'title_column',
					'choices'		=> $columns
				));
			}
			
			function render_field($field) {
				global $wpdb;

				$results = $wpdb->get_results("SELECT `".$field["id_column"]."`, `".$field["title_column"]."` FROM `".$field["table"]."` ORDER BY `".$field["title_column"]."` ASC");
?>
<select name="<?php echo esc_attr($field['name']) ?>">
	<option></option>
	<?php foreach ($results as $result) { ?>
	<option value="<?=$result->{$field["id_column"]}?>" <?php if ($result->{$field["id_column"]} == $field['value']) { ?> selected="selected"<?php } ?>><?=$result->{$field["title_column"]}?></option>
	<?php } ?>
</select>
<?php
			}			
		}
		
		
		new ACFFieldDatabaseContentSelect($this->settings);
		
	}
?>