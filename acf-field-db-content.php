<?php
	/*
		Plugin Name: Advanced Custom Fields: Database Content Field
		Plugin URI: https://www.github.com/timbuckingham/acf-field-db-content
		Description: Provides an ACF field that allows you to relate to content in another table in the WordPress database.
		Version: 1.0.0
		Author: Tim Buckingham
		Author URI: https://www.github.com/timbuckingham
		License: LGPLv2 or later
		License URI: http://www.gnu.org/licenses/lgpl-2.0.html
	*/
	
	// Exit if accessed directly
	if (!defined('ABSPATH')) {
		exit;
	}

	// Check if class already exists
	if (!class_exists('ACFDatabaseContentFields')) {
	
		class ACFDatabaseContentFields {
				
			function __construct() {
				$this->settings = array(
					'version' => '1.0.0',
					'url' => plugin_dir_url(__FILE__),
					'path' => plugin_dir_path(__FILE__)
				);
								
				load_plugin_textdomain('acf-field-db-content', false, plugin_basename(dirname( __FILE__ )).'/lang'); 				
				add_action('acf/include_field_types', array($this, 'include_field_types'));				
			}
			
			function include_field_types($version = false) {
				include_once('fields/database-content-select.php');
			}

			static function describeTable($table) {
				global $wpdb;

				$result["columns"] = array();
				$result["indexes"] = array();
				$result["foreign_keys"] = array();
				$result["primary_key"] = false;
				
				$results = $wpdb->get_results("SHOW CREATE TABLE `".str_replace("`","",$table)."`");
				
				if (!count($results)) {
					return false;
				}

				$result_properties = get_object_vars($results[0]);
				$lines = explode("\n", $result_properties["Create Table"]);
				// Line 0 is the create line and the last line is the collation and such. Get rid of them.
				$main_lines = array_slice($lines,1,-1);
				
				foreach ($main_lines as $line) {
					$column = array();
					$line = rtrim(trim($line),",");
					
					if (strtoupper(substr($line,0,3)) == "KEY" || strtoupper(substr($line,0,10)) == "UNIQUE KEY") { // Keys
						if (strtoupper(substr($line,0,10)) == "UNIQUE KEY") {
							$line = substr($line,12); // Take away "KEY `"
							$unique = true;
						} else {
							$line = substr($line,5); // Take away "KEY `"
							$unique = false;
						}

						// Get the key's name.
						$key_name = static::nextSQLColumnDefinition($line);
						
						// Get the key's content
						$line = substr($line,strlen($key_name) + substr_count($key_name,"`") + 4); // Skip ` (`
						$line = substr(rtrim($line,","),0,-1); // Remove trailing , and )
						$key_parts = array();
						$part = true;
						
						while ($line && $part) {
							$part = static::nextSQLColumnDefinition($line);
							$size = false;
							
							// See if there's a size definition, include it
							if (substr($line,strlen($part) + 1,1) == "(") {
								$line = substr($line,strlen($part) + 1);
								$size = substr($line,1,strpos($line,")") - 1);
								$line = substr($line,strlen($size) + 4);
							} else {
								$line = substr($line,strlen($part) + substr_count($part,"`") + 3);
							}

							if ($part) {
								$key_parts[] = array("column" => $part,"length" => $size);
							}
						}

						$result["indexes"][$key_name] = array("unique" => $unique,"columns" => $key_parts);
					} elseif (strtoupper(substr($line,0,7)) == "PRIMARY") { // Primary Keys
						$line = substr($line,14); // Take away PRIMARY KEY (`
						$key_parts = array();
						$part = true;
						
						while ($line && $part) {
							$part = static::nextSQLColumnDefinition($line);
							$line = substr($line,strlen($part) + substr_count($part,"`") + 3);
							
							if ($part) {
								if (strpos($part,"KEY_BLOCK_SIZE=") === false) {
									$key_parts[] = $part;
								}
							}
						}

						$result["primary_key"] = $key_parts;
					} elseif (strtoupper(substr($line,0,10)) == "CONSTRAINT") { // Foreign Keys
						$line = substr($line,12); // Remove CONSTRAINT `
						$key_name = static::nextSQLColumnDefinition($line);
						$line = substr($line,strlen($key_name) + substr_count($key_name,"`") + 16); // Remove ` FOREIGN KEY (`
						
						// Get local reference columns
						$local_columns = array();
						$part = true;
						$end = false;
						
						while (!$end && $part) {
							$part = static::nextSQLColumnDefinition($line);
							$line = substr($line,strlen($part) + 1); // Take off the trailing `
							
							if (substr($line,0,1) == ")") {
								$end = true;
							} else {
								$line = substr($line,2); // Skip the ,` 
							}

							$local_columns[] = $part;
						}
	
						// Get other table name
						$line = substr($line,14); // Skip ) REFERENCES `
						$other_table = static::nextSQLColumnDefinition($line);
						$line = substr($line,strlen($other_table) + substr_count($other_table,"`") + 4); // Remove ` (`
	
						// Get other table columns
						$other_columns = array();
						$part = true;
						$end = false;
						
						while (!$end && $part) {
							$part = static::nextSQLColumnDefinition($line);
							$line = substr($line,strlen($part) + 1); // Take off the trailing `
							
							if (substr($line,0,1) == ")") {
								$end = true;
							} else {
								$line = substr($line,2); // Skip the ,` 
							}

							$other_columns[] = $part;
						}
	
						$line = substr($line,2); // Remove ) 
						
						// Setup our keys
						$result["foreign_keys"][$key_name] = array("local_columns" => $local_columns, "other_table" => $other_table, "other_columns" => $other_columns);
	
						// Figure out all the on delete, on update stuff
						$pieces = explode(" ",$line);
						$on_hit = false;
						$current_key = "";
						$current_val = "";
						
						foreach ($pieces as $piece) {
							if ($on_hit) {
								$current_key = strtolower("on_".$piece);
								$on_hit = false;
							} elseif (strtoupper($piece) == "ON") {
								if ($current_key) {
									$result["foreign_keys"][$key_name][$current_key] = $current_val;
									$current_key = "";
									$current_val = "";
								}

								$on_hit = true;
							} else {
								$current_val = trim($current_val." ".$piece);
							}
						}

						if ($current_key) {
							$result["foreign_keys"][$key_name][$current_key] = $current_val;
						}
					} elseif (substr($line,0,1) == "`") { // Column Definition
						$line = substr($line,1); // Get rid of the first `
						$key = static::nextSQLColumnDefinition($line); // Get the column name.
						$line = substr($line,strlen($key) + substr_count($key,"`") + 2); // Take away the key from the line.
						
						$size = "";
						// We need to figure out if the next part has a size definition
						$parts = explode(" ",$line);
						
						if (strpos($parts[0],"(") !== false) { // Yes, there's a size definition
							$type = "";
							// We're going to walk the string finding out the definition.
							$in_quotes = false;
							$finished_type = false;
							$finished_size = false;
							$x = 0;
							$options = array();
							
							while (!$finished_size) {
								$c = substr($line,$x,1);
								
								if (!$finished_type) { // If we haven't finished the type, keep working on it.
									if ($c == "(") { // If it's a (, we're starting the size definition
										$finished_type = true;
									} else { // Keep writing the type
										$type .= $c;
									}
								} else { // We're finished the type, working in size definition
									if (!$in_quotes && $c == ")") { // If we're not in quotes and we encountered a ) we've hit the end of the size
										$finished_size = true;
									} else {
										if ($c == "'") { // Check on whether we're starting a new option, ending an option, or adding to an option.
											if (!$in_quotes) { // If we're not in quotes, we're starting a new option.
												$current_option = "";
												$in_quotes = true;
											} else {
												if (substr($line,$x + 1,1) == "'") { // If there's a second ' after this one, it's escaped.
													$current_option .= "'";
													$x++;
												} else { // We closed an option, add it to the list.
													$in_quotes = false;
													$options[] = $current_option;
												}
											}
										} else { // It's not a quote, it's content.
											if ($in_quotes) {
												$current_option .= $c;
											} elseif ($c != ",") { // We ignore commas, they're just separators between ENUM options.
												$size .= $c;
											}
										}
									}
								}

								$x++;
							}

							$line = substr($line,$x);
						} else { // No size definition
							$type = $parts[0];
							$line = substr($line,strlen($type) + 1);
						}
						
						$column["name"] = $key;
						$column["type"] = $type;
						
						if ($size) {
							$column["size"] = $size;
						}

						if ($type == "enum") {
							$column["options"] = $options;
						}

						$column["allow_null"] = true;
						$extras = explode(" ",$line);
						
						for ($x = 0; $x < count($extras); $x++) {
							$part = strtoupper($extras[$x]);
							
							if ($part == "NOT" && strtoupper($extras[$x + 1]) == "NULL") {
								$column["allow_null"] = false;
								$x++; // Skip NULL
							} elseif ($part == "CHARACTER" && strtoupper($extras[$x + 1]) == "SET") {
								$column["charset"] = $extras[$x + 2];
								$x += 2;
							} elseif ($part == "DEFAULT") {
								$default = "";
								$x++;
								
								if (substr($extras[$x],0,1) == "'") {
									while (substr($default,-1,1) != "'") {
										$default .= " ".$extras[$x];
										$x++;
									}
								} else {
									$default = $extras[$x];
								}

								$column["default"] = trim(trim($default),"'");
							} elseif ($part == "COLLATE") {
								$column["collate"] = $extras[$x + 1];
								$x++;
							} elseif ($part == "ON") {
								$column["on_".strtolower($extras[$x + 1])] = $extras[$x + 2];
								$x += 2;
							} elseif ($part == "AUTO_INCREMENT") {
								$column["auto_increment"] = true;
							} elseif ($part == "UNSIGNED") {
								$column["unsigned"] = true;
							}
						}
						
						$result["columns"][$key] = $column;
					}
				}
				
				$last_line = substr(end($lines),2);
				$parts = explode(" ",$last_line);
				
				foreach ($parts as $part) {
					list($key,$value) = explode("=",$part);
					
					if ($key && $value) {
						$result[strtolower($key)] = $value;
					}
				}
				
				return $result;
			}

			static function nextSQLColumnDefinition($string) {
				$key_name = "";
				$i = 0;
				$found_key = false;
				
				// Apparently we can have a backtick ` in a column name... ugh.
				while (!$found_key && $i < strlen($string)) {
					$char = substr($string,$i,1);
					$second_char = substr($string,$i + 1,1);
					
					if ($char != "`" || $second_char == "`") {
						$key_name .= $char;
						
						if ($char == "`") { // Skip the next one, this was just an escape character.
							$i++;
						}
					} else {
						$found_key = true;
					}

					$i++;
				}

				return $key_name;
			}
			
		}
	
		// initialize
		new ACFDatabaseContentFields;
	}
