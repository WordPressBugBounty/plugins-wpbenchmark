<?php

defined('ABSPATH') or die("No script kiddies please!");

class wpbenchmarkio {
	private static $plugin_version = 1;
	private static $talk_to = "https://collect.wpbenchmark.io/tell_me.php";

	var $object_cache_key_count = 250;
	var $object_cache_group_count = 50;


	var $dbtest_object_types = array("ocean", "mountain", "space", "earth");
	var	$dbtest_object_properties = array("name", "size", "value", "data1", "data2");

	var $dbtables = array();

	function __construct() {}

	function request_new($m=array()) {
		$settings = get_option("wp-benchmark-io-settings");


		$m["a"] = "register_new";
		$m["site_url"] = get_site_url();
		$m["anonymize_after"]=$settings["anonymize_after"];
		$data = $this->talk($m);

		if (isset($data["bench_code"])) {

			if (!isset($data["expire_in"]))
				$data["expire_in"]=600;

			list($steps, $average_times)=$this->get_initial_steps();
		
			$running_benchmark = array(
				"bench_code"=>$data["bench_code"],
				"progress"=>0,
				"expire_in"=>$data["expire_in"],
				"steps"=>$steps,
				"average_times"=>$average_times,
				"show_on_board"=>$settings["show_on_board"],
				"run_lite_tests"=>$settings["run_lite_tests"],
				"skip_object_cache_tests"=>$settings["skip_object_cache_tests"]
			);

			$running_benchmark["group_progress"] = $this->get_test_progress($running_benchmark);

			return($running_benchmark);
		} else
			throw new Exception("Empty benchmark ID, try again later.");
	}


	function talk($data) {
		if (isset($_SERVER["REMOTE_ADDR"]))
			$data["wp_user_ip"] = $_SERVER["REMOTE_ADDR"];

		$data["plugin_version"] = self::$plugin_version;

		$response = wp_remote_post(self::$talk_to, array("body"=>$data));
		if( is_wp_error( $response ) ) {
			throw new Exception($response->get_error_message());
		}

		
		return(json_decode(wp_remote_retrieve_body($response),true));
	}



	function run_next($bench, $skip_next=false) {

		$bench["executed_description"] = "Unknown error occured";

		$steps_total = 0;
		foreach($bench["steps"] as $group_key=>$group_data) {
			$steps_total+=count($group_data["run_tests"]);
		}


		
		if ($steps_total==0) {
			$bench["progress"]=100;
			return($bench);
		}

		$steps_completed=0;
		$step_to_run_found=false;
		$step_to_run_key  =null;
		$step_group_to_run=null;

		foreach($bench["steps"] as $group_key=>$group_data) {

			if ($step_to_run_found===false) {

				foreach($group_data["run_tests"] as $sk=>$sv) {
					if ($sv["is_complete"]===true)
						$steps_completed++;

					if ($step_to_run_found===false && $sv["is_complete"]===false) {
						$step_to_run_key = $sk;
						$step_to_run_found = true;
						$step_group_to_run = $group_key;
					} 
				}
			}

		}


		if ($step_to_run_found) {

			$function_name = $bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["function"];

			if ($skip_next) {
				// skipping this failed test
				$time_spent = -1;

				$bench["average_times"][$function_name]["times_run"]++;
				$bench["average_times"][$function_name]["total_time"] = -1;
			} else {

				# if set - do some preparation before running actual benchmark
				if (isset($bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["prepare_function"])) {
					$prepare_function = $bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["prepare_function"];

					if ($prepare_function!="")
						$this->$prepare_function();
				}

				$start_time = microtime(true);
				if (!$this->$function_name())
					$time_spent = 0;
				else
					$time_spent = microtime(true)-$start_time;

				
				# if set - let's do a mess cleanup after the benchmark
				if (isset($bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["cleanup_function"])) {
					$cleanup_function = $bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["cleanup_function"];

					if ($cleanup_function!="")
						$this->$cleanup_function();
				}



				$time_spent=(int)($time_spent*100000);
				$time_spent=$time_spent/100;

				$bench["average_times"][$function_name]["times_run"]++;
				$bench["average_times"][$function_name]["total_time"]+=$time_spent;

			}


			$steps_completed++;
			$bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["is_complete"]=true;
			$bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["measured_time"]=$time_spent;
			# $bench["executed_description"]=$bench["steps"][$step_to_run_key]["name"] . ": ".$bench["steps"][$step_to_run_key]["measured_time"]."ms";
			$bench["executed_description"]=$bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["name"] . ": ".$bench["steps"][$step_group_to_run]["run_tests"][$step_to_run_key]["measured_time"]."ms";

		}

		if ($steps_completed==$steps_total) {
			$bench["progress"]=100;
		} else {
			$bench["progress"]=(int)(($steps_completed/$steps_total)*100);
		}

		$bench["group_progress"] = $this->get_test_progress($bench);

		return($bench);
	}


	function calculate_finals($bench) {


		$this->clean_tmp_folder();
		$this->clean_db_test_tables();

		$dsc = "";
		foreach($bench["average_times"] as $fn=>$fd) {
			$bench["average_times"][$fn]["average_time"]=round($fd["total_time"]/$fd["times_run"]);
		 	# $dsc .= $fn.": ".$bench["average_times"][$fn]["average_time"]."ms<br>";
		}
		# executed_description

		$site_name = get_bloginfo("name");

		# post results to central database and get averages
		$our_results = array(
			"a"=>"store_results",
			"bench_code"=>$bench["bench_code"],
			"results"=>$bench["average_times"],
			"site_url"=>get_site_url(),
			"site_title"=>$site_name,
			"php_version"=>phpversion(),
			"show_on_board"=>$bench["show_on_board"],
			"run_lite_tests"=>$bench["run_lite_tests"],
			"skip_object_cache_tests"=>$bench["skip_object_cache_tests"]
		);

		$global_averages = $this->talk($our_results);


		$bench["executed_description"] = $dsc;
		$bench["group_progress"] = $this->get_test_progress($bench);

		if (isset($global_averages["thankyou"]) && isset($global_averages["my_score"])) {
			# $bench["global_averages"] = $global_averages["averages"];

			list($function_types, $functions_to_run) = $this->get_function_defs();


			$my_results = array();
			foreach($global_averages["my_score"] as $test_function=>$test_score) {

				foreach($functions_to_run as $func_def) {
					if ($func_def["function"]==$test_function) {
						$my_results[$test_function]=array("title"=>$func_def["name"], "url"=>$func_def["url"], "test_score"=>$test_score, "score_ratio"=>$func_def["test_ratio"]);
					}
				}

				
			}


			$host_score = 0;
			$score_elements = 0;

			$dsc = "<div class='wpio-result-container'>";

			$skip_object_cache_metrics = false;

			foreach($function_types as $ftype=>$ftype_def) {

				$add_this_ftype = true;

				if ($our_results["skip_object_cache_tests"]==1 && $ftype=="object_cache") 
					$add_this_ftype = false;


				if ($add_this_ftype) {

					$dsc .= "
					<div class='wpio-flex-row wpio-fntype-row'>
						<div class='wpio-flex-col wpio-type-col'>
							".$ftype_def["name"]."
						</div>
						<div class='wpio-flex-col'>
					";


					foreach($functions_to_run as $func_def) {
						if ($func_def["type"]==$ftype) {
							
							$f_score = $my_results[$func_def["function"]]["test_score"];
							$f_ratio = $my_results[$func_def["function"]]["score_ratio"];

							


							if (!$skip_object_cache_metrics || $func_def["type"]!="object_cache") {
								$host_score += $f_score*$f_ratio;
								$score_elements += $f_ratio;

								if ($f_score<2)
									$row_class = "wpio-col-score02";
								else if ($f_score<5)
									$row_class = "wpio-col-score25";
								else if ($f_score<6)
									$row_class = "wpio-col-score56";
								else if ($f_score<7)
									$row_class = "wpio-col-score67";
								else if ($f_score<8)
									$row_class = "wpio-col-score78";
								else if ($f_score<9)
									$row_class = "wpio-col-score89";
								else
									$row_class = "wpio-col-score910";

								$dsc .= "
									<div class='wpio-flex-row'>
										<div class='wpio-flex-col wpio-text-black'>".$my_results[$func_def["function"]]["title"]."</div>
										<div class='wpio-flex-col wpio-score-col ".$row_class."'>".$f_score."</div>
									</div>
								";
							}

							if ($f_score==0 && $func_def["function"]=="test_has_persistent_oc")
								$skip_object_cache_metrics = true;
						}
					}

					$dsc .= "
						</div>
					</div>";		
				}

			}


			#$total_score = round(($host_score/$score_elements)*10)/10;
			$total_score = $global_averages["my_score"]["total"];
			#$dsc .= "<tr><td colspan=2 class='total-score'></td></tr>";

			if ($total_score<2)
				$row_class = "wpio-col-score02";
			else if ($total_score<5)
				$row_class = "wpio-col-score25";
			else if ($total_score<6)
				$row_class = "wpio-col-score56";
			else if ($total_score<7)
				$row_class = "wpio-col-score67";
			else if ($total_score<8)
				$row_class = "wpio-col-score78";
			else if ($total_score<9)
				$row_class = "wpio-col-score89";
			else
				$row_class = "wpio-col-score910";


			$dsc_php_version_recommendation = "";
			if (PHP_VERSION_ID<80200) {
				# version is less than PHP8
				$dsc_php_version_recommendation = "
				<div class='wpio-flex-row'>
					<div class='wpio-flex-col'><strong>Tip:</strong> Your are using <strong>PHP ".phpversion()."</strong>, which is quite outdated. PHP 8.3+ offers great improvement in performance. If your plugins and theme support newer PHP version - upgrading could improve responsivness of your website.</div>
				</div>
				";

			} else if (PHP_VERSION_ID<80300) {
				# version is less than 8.3

				$dsc_php_version_recommendation = "
				<div class='wpio-flex-row'>
					<div class='wpio-flex-col'><strong>" . PHP_VERSION_ID . " Tip:</strong> Your are using <strong>PHP ".phpversion()."</strong> - further upgrade to latest PHP 8.4 can improve your website performance.</div>
				</div>
				";

				
			}

			$dsc .= "
				<div class='wpio-flex-row'>
					<div class='wpio-flex-col col-read-more'>" . (($bench["anonymize_after"]=="at_once")?"&nbsp;":"<a href='https://report.wpbenchmark.io/" . $bench["bench_code"] . "/' target=_blank>Read more</a>") . "</div>
					<div class='wpio-flex-col total-score'>Your server score</div>
					<div class='wpio-flex-col total-score-markcol ".$row_class."'>".$total_score."</div>
				</div>

				".(($bench["anonymize_after"]!="at_once")?"
				<div class='wpio-flex-row'>
					<div class='wpio-flex-col col-read-more' style='text-align:center;'><i>* Please check <u><a href='https://report.wpbenchmark.io/" . $bench["bench_code"] . "/' target=_blank>read more</a></u> page for details of page loading timings</i>
					</div>
				</div>
				":"")."

				".$dsc_php_version_recommendation."				

				".(($total_score<8)?"
					<div class='wpio-flex-row'>
						<div class='wpio-flex-col col-read-more'><a href='https://wpbenchmark.io/improve-wordpress-speed/' target=_blank><span style='background: green; color: white; border-radius: 3px; padding: 2px 10px;'>tips for performance improvement</span></a>
						</div>
					</div>
				":"")."
			";

			$dsc .= "</div> <!-- end flex container -->";
			# $dsc .= "</table>";

			$bench["executed_description"] = $dsc;
			$bench["total_score"]=$total_score;
		} else {
			$bench["global_averages"] = array();
			$bench["executed_description"].="<span style='color:red;'>Failed to load global averages!</span>";
		}

		return($bench);
	}


	function get_function_defs() {
		$function_types = array(
			"cpu_memory"=>array("name"=>"CPU &amp; Memory", "progress"=>0, "run_tests"=>array()),
			"filesystem"=>array("name"=>"Filesystem", "progress"=>0, "run_tests"=>array()),
			"database"=>array("name"=>"Database", "progress"=>0, "run_tests"=>array()),
			"object_cache"=>array("name"=>"Object cache", "progress"=>0, "run_tests"=>array()),
			"wordpress"=>array("name"=>"Wordpress core", "progress"=>0, "run_tests"=>array()),
			"network"=>array("name"=>"Network", "progress"=>0, "run_tests"=>array())			
		);


		# optional parameters - prepare_function , cleanup_function

		
		$functions_to_run = array();
	
		$functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_cpu_regex", "name"=>"Operations with large text data", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/random-string-operations/", "test_ratio"=>2);
		$functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_cpu_randbytes", "name"=>"Random binary data operations", "description"=>"Test CPU and memory with random binary data generation and memory prefill", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/random-binary-data/", "test_ratio"=>1);
		# new functions as of 26.february 2024
		$functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_fibo_recursive", "name"=>"Recursive mathematical calculations", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/fibonacci-recursive/", "test_ratio"=>2);
		$functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_fibo_iterative", "name"=>"Iterative mathematical calculations", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/fibonacci-iterative/", "test_ratio"=>2);
		# new functions 8.march.2025
		$functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_cpu_floating_operations", "name"=>"Floating point operations", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/cpu-floating-operations/", "test_ratio"=>3);
		# $functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_cpu_string_operations", "name"=>"String manipulations", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/cpu-string-operations/", "test_ratio"=>3);

		
		$functions_to_run[] = array("type"=>"filesystem", "function"=>"test_filewrite", "name"=>"Filesystem write ability", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/filesystem-write-speed/", "test_ratio"=>3);
		$functions_to_run[] = array("type"=>"filesystem", "function"=>"test_filecopy", "name"=>"Local file copy and access speed", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/copying-and-reading-files/", "test_ratio"=>2);
		$functions_to_run[] = array("type"=>"filesystem", "function"=>"test_filewrite_smallfiles", "name"=>"Small file IO test", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/filesystem-write-speed/", "test_ratio"=>3);



		$functions_to_run[] = array("type"=>"database", "function"=>"test_db_insert", "name"=>"Importing large amount of data to database", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/database-data-import/", "test_ratio"=>2);
		$functions_to_run[] = array("type"=>"database", "function"=>"test_db_simple", "name"=>"Simple queries on single table", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/accessing-database-data/", "test_ratio"=>3);
		$functions_to_run[] = array("type"=>"database", "function"=>"test_db_joins", "name"=>"Complex database queries on multiple tables", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/complex-database-queries/", "test_ratio"=>4);


		# prepare_function=must_have_persistent_cache
		$functions_to_run[] = array("type"=>"object_cache", "function"=>"test_has_persistent_oc", "name"=>"Persistent object cache enabled", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/has-persistent-object-cache/", "test_ratio"=>1, "prepare_function"=>"");

		$functions_to_run[] = array("type"=>"object_cache", "function"=>"test_oc_persistent_write", "name"=>"Persistent object cache write", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/has-persistent-object-cache/", "test_ratio"=>1, "prepare_function"=>"reset_and_prepare_object_cache");
		$functions_to_run[] = array("type"=>"object_cache", "function"=>"test_oc_persistent_read", "name"=>"Persistent object cache read", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/has-persistent-object-cache/", "test_ratio"=>1, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"object_cache", "function"=>"test_oc_persistent_mixed", "name"=>"Persistent object cache mixed usage", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/has-persistent-object-cache/", "test_ratio"=>1, "cleanup_function"=>"fill_object_cache");


		# Wordpress tailored tests
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_shortcode_processing", "name"=>"Shortcode processing", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/shortcode-processing/", "test_ratio"=>3, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_hooks_system", "name"=>"Wordpress Hooks", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-hooks/", "test_ratio"=>3, "prepare_function"=>"");
		
		# Transients should be treated in a same way, as OPCache - Write and Read as separate tests.
		# $functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_transient_operations", "name"=>"Transient benchmark", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-transients/", "test_ratio"=>2, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_option_operations", "name"=>"Wordpress option manipulation", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-option-operations/", "test_ratio"=>2, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_regex_wordpress", "name"=>"REGEX string processing", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-regex/", "test_ratio"=>3, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_taxonomy_processing", "name"=>"Taxonomy benchmark", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-taxonomy-benchmark/", "test_ratio"=>2, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_capability_checks", "name"=>"Object capability benchmark", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-capability-benchmark/", "test_ratio"=>2, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_content_filtering", "name"=>"Content filtering", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-content-filtering/", "test_ratio"=>2, "prepare_function"=>"");
		$functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_json_processing", "name"=>"JSON manipulations", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/json-manipulations/", "test_ratio"=>2, "prepare_function"=>"");
		# $functions_to_run[] = array("type"=>"wordpress", "function"=>"test_wordpress_template_processing", "name"=>"Template processing", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/wordpress-template-processing/", "test_ratio"=>2, "prepare_function"=>"");



		$functions_to_run[] = array("type"=>"network", "function"=>"test_network_download", "name"=>"Network download speed test", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/network-download-speedtest/", "test_ratio"=>2);

		return(array($function_types, $functions_to_run));
	}




	function get_initial_steps() {

		
		list($function_types, $functions_to_run) = $this->get_function_defs();

		# "test_cpu_md5", "test_cpu_rand", "test_memory_array");

		# if (self::$settings)
		# run_lite_tests
		$settings = get_option("wp-benchmark-io-settings");
		if ($settings["run_lite_tests"]==1)
			$times_to_run=1;
		else
			$times_to_run = 5;

		$steps = array();
		$average_times = array();

		foreach($functions_to_run as $fn) {

			$add_this_function = true;


			if ($settings["skip_object_cache_tests"]==1 && $fn["type"]=="object_cache")
				$add_this_function = false;


			if ($add_this_function) {
				$average_times[$fn["function"]]=array("times_run"=>0, "total_time"=>0);

				for($i=0;$i<$times_to_run;$i++) {
					# $steps[] = $fn;			
					$function_types[$fn["type"]]["run_tests"][]=$fn;
				}
			}

		}

		$this->clean_tmp_folder();


		# flush and prepare object cache.
		$this->reset_and_prepare_object_cache();



		return(array($function_types, $average_times));
	}

	

	function reset_and_prepare_object_cache() {
		# make object cache empty 
		$this->local_wp_cache_flush();

		# add value to object cache to test - if it is permanent.
		$this->local_add_test_variable();
	}



	function random_string($len=10) {
		$avail_chars = '1234567890ABCDEFGHJIKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
		$char_len=strlen($avail_chars);
		$rand_string = "";
		for($sid_n=0; $sid_n < $len; $sid_n++) {
			$rand_string .= substr($avail_chars, rand(0, $char_len-1), 1);
		}

		return($rand_string);
	}


	function get_test_progress($bench) {
		$p = array();

		foreach($bench["steps"] as $group_key=>$group_data) {
			$group_complete = 0;
			$group_tests    = count($group_data["run_tests"]);

			foreach($group_data["run_tests"] as $t) {
				if ($t["is_complete"])
					$group_complete++;
			}

			if ($group_tests>0)
				$p[$group_key]=array("key"=>$group_key, "name"=>$group_data["name"], "group_progress"=>( ((int)(($group_complete/$group_tests)*100)) ));
			else
				unset($p[$group_key]);
		}

		return($p);
	}




	function test_cpu_md5() {
		# usleep(100);
		for ($i=0;$i<2000000;$i++) {
			$q = md5(random_string(1024));
		}

		return true;
	}

	function test_cpu_rand() {
		for ($i=0;$i<1000000;$i++) {
			$b = rand(0,1000000);
		}
		for ($i=0;$i<1000000;$i++) {
			$b = rand(0,1000000);
		}
		for ($i=0;$i<1000000;$i++) {
			$b = rand(0,1000000);
		}
		for ($i=0;$i<1000000;$i++) {
			$b = rand(0,1000000);
		}
		for ($i=0;$i<1000000;$i++) {
			$b = rand(0,1000000);
		}

		return true;
	}

	function test_cpu_randbytes($s_max=800000) {

		$a=null;
		for($i=0;$i<1500;$i++) {
			$a .= $this->random_string(10240);
		}

		$ahex = bin2hex($a);
		$ahex_capital = "";
		
		# 30 720 000
		# 1000000
		for ($s=0;$s<$s_max;$s++) {

			$ahex_capital.=strtoupper($ahex[rand(0,30000000)]);

			$temp_value = md5($ahex_capital);

			if (strlen($ahex_capital)>10240)
				$ahex_capital="";
		}

		return true;
	}

	function test_cpu_regex($i_max=100, $j_max=30, $s_length=20480) {
		$data = array();

		for ($i=0;$i<$i_max;$i++) {
			$replace_value = $this->random_string(2);
			

			$s = $replace_value.", ";

			while(mb_strlen($s)<$s_length) {
				$s.=$this->random_string(10).", ";				
			}


			for ($j=0;$j<$j_max;$j++) {
				$replace_with  = $this->random_string(2);
				$s = mb_eregi_replace($replace_value, $replace_with, $s);
				$s = mb_eregi_replace($replace_with, $replace_value, $s);
				$s = mb_eregi_replace($replace_value, $replace_with, $s);
			}

			$data[] = $s;
		}

		# if this function is called with minimal parameters - make it lighter and quit here
		if ($i_max==$j_max && $i_max==1) {
			return true;
		}

		$data_splitted_2 = array();
		$data_splitted = array();
		foreach($data as $big_string) {
			$data_splitted[]=explode(",", $big_string);
			array_merge($data_splitted_2, preg_split("/[\s,]+/", $big_string));
		}



		foreach($data_splitted as $rk=>$r_array) {
			sort($data_splitted[$rk]);
		}

		foreach($data_splitted_2 as $rk=>$rv) {
			$data_splitted_2[$rk]=md5(md5($rv));
		}

		sort($data_splitted_2);

		foreach($data_splitted as $rk=>$r_array) {
			foreach($r_array as $sk=>$sv) {
				$data_splitted[$rk][$sk]=md5($sv);
			}
		}

		unset($data);

		return true;
	}


	function wpbenchmark_fibonacci_recursive($n) {
	    if ($n <= 1) {
	        return $n;
	    } else {
	        return $this->wpbenchmark_fibonacci_recursive($n - 1) + $this->wpbenchmark_fibonacci_recursive($n - 2);
	    }
	}

	function wpbenchmark_fibonacci_iterative($n) {
	    $fib = array();
	    $fib[0] = 0;
	    $fib[1] = 1;
	    for ($i = 2; $i <= $n; $i++) {
	        $fib[$i] = $fib[$i - 1] + $fib[$i - 2];
	    }
	    # return $fib[$n];
	    return true;
	}

	# $functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_fibo_recursive", "name"=>"Recursive mathematical calcuations", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/fibonacci-recursive/", "test_ratio"=>2);
	# $functions_to_run[] = array("type"=>"cpu_memory", "function"=>"test_fibo_iterative", "name"=>"Iterative mathematical calculations", "description"=>"", "is_complete"=>false, "measured_time"=>0, "result_dsc"=>"Not run", "url"=>"/wordpress-test/fibonacci-iterative/", "test_ratio"=>2);
	function test_fibo_recursive() {
		$this->wpbenchmark_fibonacci_recursive(40);
		return true;
	}

	function test_fibo_iterative() {
		for ($i=1;$i<100000;$i++) {
		    $result = $this->wpbenchmark_fibonacci_iterative(1000); // Adjust the input value as needed
		}
		return true;
	}

	function test_function_output() {
		return "Metallica";
	}

	function run_cpu_background_test() {
		# $result = $this->test_cpu_randbytes(5);
		$result = $this->wpbenchmark_fibonacci_iterative(500);	
		$result = $this->test_cpu_regex(1, 1, 5120);

		# $result = $this->test_wordpress_json_processing(1);
		# $result = $this->test_wordpress_shortcode_processing(1,1);
		# $result = $this->test_cpu_floating_operations(500);

		return true;
	}



	# floating point
	function test_cpu_floating_operations($iterations = 200000) {
	    
	    for ($j = 0; $j < 150; $j++) {
	    	$result = 0;
		    for ($i = 0; $i < $iterations; $i++) {
		        $result += sin($i) * cos($i) / sqrt($i + 1);
		    }
		}
	    
	    return true;
	}


	# string processing and memory manipulations
	function test_cpu_string_operations($iterations = 2000000) {
	    # $start = microtime(true);
	    $text = "The quick brown fox jumps over the lazy dog.";
	    for ($i = 0; $i < $iterations; $i++) {
	        $text = strrev($text);
	        $text = str_replace("o", "0", $text);
	        $text = strtoupper($text);
	    }
	    #return microtime(true) - $start;
	    return true;
	}



	###############
	# Wordpress specific

	# Transient benchmark

	/**
	 * 1. WordPress Shortcode Processing
	 * Tests CPU performance with WordPress shortcode parsing and rendering
	 */
	function test_wordpress_shortcode_processing($nested_level=4, $repeat_times=15) {
	    // Create a complex nested shortcode string
	    # $nested_level = 4;
	    $content = "Start content ";
	    
	    // Generate nested shortcodes - this mimics real WordPress content
	    for ($i = 0; $i < $nested_level; $i++) {
	        $content .= "[wpbenchmak_columns width=\"1/2\"]" . $content . "[/wpbenchmak_columns]";
	        $content .= "[wpbenchmak_row]" . $content . "[/wpbenchmak_row]";
	        $content .= "[wpbenchmak_section padding=\"10px\"]" . $content . "[/wpbenchmak_section]";
	        $content .= "[wpbenchmak_tabs]" . $content . "[/wpbenchmak_tabs]";
	    }
	    
	    // Add some custom shortcodes to process
	    add_shortcode('wpbenchmak_columns', function($atts, $content = '') {
	        return '<div class="column ' . $atts['width'] . '">' . do_shortcode($content) . '</div>';
	    });
	    
	    add_shortcode('wpbenchmak_row', function($atts, $content = '') {
	        return '<div class="row">' . do_shortcode($content) . '</div>';
	    });
	    
	    add_shortcode('wpbenchmak_section', function($atts, $content = '') {
	        return '<section style="padding:' . $atts['padding'] . '">' . do_shortcode($content) . '</section>';
	    });
	    
	    add_shortcode('wpbenchmak_tabs', function($atts, $content = '') {
	        return '<div class="tabs">' . do_shortcode($content) . '</div>';
	    });
	    
	    // Process shortcodes multiple times to benchmark CPU
	    for ($i = 0; $i < $repeat_times; $i++) {
	        $processed = do_shortcode($content);
	    }
	    
	    // Clean up - remove our test shortcodes
	    remove_shortcode('wpbenchmak_columns');
	    remove_shortcode('wpbenchmak_row');
	    remove_shortcode('wpbenchmak_section');
	    remove_shortcode('wpbenchmak_tabs');
	    
	    return true;
	}

	/**
	 * 2. WordPress Hooks System Benchmark
	 * Tests how efficiently WordPress can handle hooks and filters
	 */
	function test_wordpress_hooks_system() {
	    // Create a test function to be called
	    $test_function = function($value) {
	        return md5($value . rand(1000, 9999));
	    };
	    
	    // Add many hooks with different priorities
	    for ($i = 0; $i < 100; $i++) {
	        add_filter('test_benchmark_filter', $test_function, $i);
	    }
	    
	    // Execute filter multiple times with different values
	    for ($i = 0; $i < 100000; $i++) {
	        $result = apply_filters('test_benchmark_filter', "test_value_$i");
	    }
	    
	    // Clean up all hooks to avoid side effects
	    remove_all_filters('test_benchmark_filter');
	    
	    return true;
	}

	/**
	 * 3. WordPress Transient API Benchmark
	 * Tests CPU performance with serialization/deserialization of complex data
	 */
	function test_wordpress_transient_operations() {
	    // Generate complex test data
	    $complex_data = array();
	    for ($i = 0; $i < 100; $i++) {
	        $complex_data[] = array(
	            'id' => $i,
	            'title' => md5(rand(1000, 9999)),
	            'content' => str_repeat('WordPress is a state-of-the-art publishing platform. ', 5),
	            'meta' => array(
	                'key1' => rand(1000, 9999),
	                'key2' => str_repeat('a', rand(10, 30)),
	                'key3' => array('nested' => true, 'count' => $i)
	            )
	        );
	    }
	    
	    // Set and get transients repeatedly
	    for ($i = 0; $i < 10000; $i++) {
	        // Mix of operations
	        set_transient('benchmark_transient_' . $i, $complex_data, 60);
	        $result = get_transient('benchmark_transient_' . $i);
	        delete_transient('benchmark_transient_' . $i);
	    }
	    
	    return true;
	}

	/**
	 * 4. WordPress Option API Benchmark
	 * Tests CPU with options table operations (serialization/deserialization)
	 */
	function test_wordpress_option_operations() {
	    // Create a large, complex option
	    $large_option = array();
	    for ($i = 0; $i < 1000; $i++) {
	        $large_option["key_$i"] = array(
	            'name' => "Test name $i",
	            'value' => str_repeat('test value ', 10),
	            'attributes' => array(
	                'color' => '#' . dechex(rand(0, 16777215)),
	                'weight' => rand(100, 900),
	                'nested' => array(
	                    'level' => $i,
	                    'active' => ($i % 2 == 0)
	                )
	            )
	        );
	    }
	    
	    // Benchmark option updates and gets
	    for ($i = 0; $i < 200; $i++) {
	        update_option('benchmark_option_' . $i, $large_option);
	        $retrieved = get_option('benchmark_option_' . $i);
	        delete_option('benchmark_option_' . $i);
	    }
	    
	    return true;
	}

	/**
	 * 5. Regular Expression Performance
	 * WordPress uses regex extensively for content processing
	 */
	function test_wordpress_regex_wordpress() {
	    // Create content that mimics WordPress post content with shortcodes, HTML, etc.
	    $content = '';
	    for ($i = 0; $i < 200; $i++) {
	        $content .= "<p>This is paragraph {$i} with [shortcode attr=\"value\"]some content[/shortcode].</p>\n";
	        $content .= "<div class=\"class-{$i}\" data-attr=\"test\">\n";
	        $content .= "  <h2>Heading {$i}</h2>\n";
	        $content .= "  <img src=\"https://example.com/image-{$i}.jpg\" alt=\"Image {$i}\">\n";
	        $content .= "  <!-- wp:paragraph {\"align\":\"center\"} -->\n";
	        $content .= "  <p>This is a Gutenberg paragraph with alignment.</p>\n";
	        $content .= "  <!-- /wp:paragraph -->\n";
	        $content .= "</div>\n";
	    }
	    
	    // Run regex patterns similar to what WordPress uses
	    for ($i = 0; $i < 15000; $i++) {
	        // Find shortcodes
	        preg_match_all('/\[([^\s\]]+)([^\]]*)\](.*?)\[\/\1\]/s', $content, $shortcodes);
	        
	        // Extract Gutenberg blocks
	        preg_match_all('/<!-- wp:([^\s]+) (.*?) -->(.*?)<!-- \/wp:\1 -->/s', $content, $blocks);
	        
	        // Find all images
	        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $images);
	        
	        // Replace URLs with https
	        $content_https = preg_replace('/(http:\/\/[^\s"\']+)/', 'https://\\1', $content);
	        
	        // Auto-paragraph function (simplified version of wpautop)
	        $paragraphed = preg_replace('/<p>(.*?)<\/p>/', "\n\n\\1\n\n", $content);
	        $paragraphed = preg_replace('/\n\n+/', "\n\n", $paragraphed);
	        $paragraphed = preg_replace('/\n\n(.+?)(?=\n\n|\z)/s', "<p>\\1</p>", $paragraphed);
	    }
	    
	    return true;
	}

	/**
	 * 6. Taxonomy Relationship Processing
	 * Simulates WordPress taxonomy operations
	 */
	function test_wordpress_taxonomy_processing() {
	    $terms = array();
	    $objects = array();
	    
	    // Create mock taxonomy data
	    for ($i = 0; $i < 200; $i++) {
	        $terms[] = array(
	            'term_id' => $i,
	            'name' => "Term $i",
	            'slug' => "term-$i",
	            'taxonomy' => ($i % 3 == 0) ? 'category' : 'post_tag'
	        );
	    }
	    
	    // Create mock objects (posts)
	    for ($i = 0; $i < 500; $i++) {
	        $objects[] = array(
	            'ID' => $i,
	            'post_title' => "Post $i",
	            'post_type' => ($i % 5 == 0) ? 'page' : 'post'
	        );
	    }
	    
	    // Create relationships between terms and objects
	    $relationships = array();
	    for ($i = 0; $i < 2500; $i++) {
	        $term_id = rand(0, 99);
	        $object_id = rand(0, 499);
	        $relationships[] = array(
	            'term_id' => $term_id,
	            'object_id' => $object_id
	        );
	    }
	    
	    // Process taxonomy relationships
	    for ($i = 0; $i < 10000; $i++) {
	        // Find objects for a specific term (simulating get_objects_in_term)
	        $term_id = rand(0, 99);
	        $objects_in_term = array_filter($relationships, function($rel) use ($term_id) {
	            return $rel['term_id'] == $term_id;
	        });
	        $object_ids = array_map(function($rel) {
	            return $rel['object_id'];
	        }, $objects_in_term);
	        
	        // Find terms for a specific object (simulating wp_get_object_terms)
	        $object_id = rand(0, 499);
	        $terms_for_object = array_filter($relationships, function($rel) use ($object_id) {
	            return $rel['object_id'] == $object_id;
	        });
	        $term_ids = array_map(function($rel) {
	            return $rel['term_id'];
	        }, $terms_for_object);
	    }
	    
	    return true;
	}

	/**
	 * 7. WordPress Capability Checks
	 * Simulates WordPress permission checking which is CPU-intensive
	 */
	function test_wordpress_capability_checks() {
	    // Create mock roles and capabilities
	    $roles = array(
	        'administrator' => array(
	            'read' => true,
	            'edit_posts' => true,
	            'delete_posts' => true,
	            'publish_posts' => true,
	            'edit_published_posts' => true,
	            'edit_others_posts' => true,
	            'delete_others_posts' => true,
	            'manage_options' => true,
	            'moderate_comments' => true,
	            // Add many more caps
	            'custom_cap_1' => true,
	            'custom_cap_2' => true,
	            'level_10' => true,
	        ),
	        'editor' => array(
	            'read' => true,
	            'edit_posts' => true,
	            'delete_posts' => true,
	            'publish_posts' => true,
	            'edit_published_posts' => true,
	            'edit_others_posts' => true,
	            'level_7' => true,
	            // More caps
	        ),
	        'author' => array(
	            'read' => true,
	            'edit_posts' => true,
	            'delete_posts' => true,
	            'publish_posts' => true,
	            'level_2' => true,
	        ),
	        'contributor' => array(
	            'read' => true,
	            'edit_posts' => true,
	            'level_1' => true,
	        ),
	        'subscriber' => array(
	            'read' => true,
	            'level_0' => true,
	        )
	    );
	    
	    // Add 20 custom roles with various capabilities
	    for ($i = 0; $i < 200; $i++) {
	        $custom_role = array('read' => true);
	        for ($j = 0; $j < 30; $j++) {
	            $cap = "custom_cap_" . $j;
	            $custom_role[$cap] = (rand(0, 1) == 1);
	        }
	        $roles["custom_role_$i"] = $custom_role;
	    }
	    
	    // Create mock users with different roles
	    $users = array();
	    for ($i = 0; $i < 1000; $i++) {
	        $role_keys = array_keys($roles);
	        $role = $role_keys[array_rand($role_keys)];
	        
	        $users[] = array(
	            'ID' => $i,
	            'user_login' => "user$i",
	            'role' => $role,
	            'roles' => array($role),
	            'capabilities' => $roles[$role]
	        );
	    }
	    
	    // Run capability checks
	    $check_caps = array(
	        'read', 'edit_posts', 'publish_posts', 'delete_others_posts', 
	        'manage_options', 'moderate_comments', 'custom_cap_1'
	    );
	    
	    for ($i = 0; $i < 2000000; $i++) {
	        $user = $users[array_rand($users)];
	        $cap = $check_caps[array_rand($check_caps)];
	        
	        // Simulate capability check (simplified map_meta_cap logic)
	        $has_cap = isset($user['capabilities'][$cap]) && $user['capabilities'][$cap];
	        
	        // Check for level capabilities (WordPress backward compatibility)
	        if (!$has_cap) {
	            foreach ($user['capabilities'] as $user_cap => $has) {
	                if (strpos($user_cap, 'level_') === 0 && $has) {
	                    $level = intval(substr($user_cap, 6));
	                    // Some logic based on level
	                    if ($cap == 'read' && $level >= 0) {
	                        $has_cap = true;
	                    } elseif ($cap == 'edit_posts' && $level >= 1) {
	                        $has_cap = true;
	                    } elseif ($cap == 'publish_posts' && $level >= 3) {
	                        $has_cap = true;
	                    }
	                }
	            }
	        }
	    }
	    
	    return true;
	}

	/**
	 * 8. WordPress Post Content Filtering
	 * Tests performance of content filters which are used extensively
	 */
	function test_wordpress_content_filtering() {
	    // Create large post content
	    $post_content = str_repeat("WordPress is a state-of-the-art publishing platform. ", 100);
	    $post_content .= str_repeat("<p>This is a paragraph with <a href=\"https://example.com\">links</a> and <strong>formatting</strong>.</p>", 50);
	    $post_content .= str_repeat("[gallery ids=\"1,2,3,4,5\"]", 10);
	    $post_content .= str_repeat("<!-- wp:paragraph --><p>This is a Gutenberg paragraph.</p><!-- /wp:paragraph -->", 30);
	    
	    // Add content filters (simulating WordPress behavior)
	    add_filter('the_content', 'wptexturize');
	    add_filter('the_content', 'convert_smilies');
	    add_filter('the_content', 'convert_chars');
	    add_filter('the_content', 'wpautop');
	    add_filter('the_content', 'shortcode_unautop');
	    add_filter('the_content', 'do_shortcode', 11);
	    
	    // Custom content filter
	    add_filter('the_content', function($content) {
	        // Replace URLs
	        $content = preg_replace('/(https?:\/\/[^\s"\'<>]+)/', '<a href="$1">$1</a>', $content);
	        // Add heading anchors
	        $content = preg_replace('/(<h[2-6][^>]*>)(.+?)(<\/h[2-6]>)/', '$1<a id="$2">$2</a>$3', $content);
	        return $content;
	    });
	    
	    // Process content multiple times
	    for ($i = 0; $i < 2000; $i++) {
	        $filtered_content = apply_filters('the_content', $post_content);
	    }
	    
	    // Clean up
	    remove_all_filters('the_content');
	    
	    return true;
	}

	/**
	 * 9. JSON Processing (WP REST API)
	 * Tests CPU performance with JSON operations
	 */
	function test_wordpress_json_processing($repeat_times=500) {
	    // Create data structure similar to WordPress REST API responses
	    $posts = array();
	    for ($i = 0; $i < 500; $i++) {
	        $posts[] = array(
	            'id' => $i,
	            'date' => date('Y-m-d H:i:s', time() - rand(0, 10000000)),
	            'date_gmt' => date('Y-m-d H:i:s', time() - rand(0, 10000000)),
	            'guid' => array('rendered' => 'https://example.com/?p=' . $i),
	            'modified' => date('Y-m-d H:i:s', time() - rand(0, 1000000)),
	            'modified_gmt' => date('Y-m-d H:i:s', time() - rand(0, 1000000)),
	            'slug' => 'post-' . $i,
	            'status' => 'publish',
	            'type' => 'post',
	            'link' => 'https://example.com/post-' . $i,
	            'title' => array('rendered' => 'Post Title ' . $i),
	            'content' => array(
	                'rendered' => '<p>This is test content for post ' . $i . '</p>',
	                'protected' => false
	            ),
	            'excerpt' => array(
	                'rendered' => 'Excerpt for post ' . $i,
	                'protected' => false
	            ),
	            'author' => rand(1, 10),
	            'featured_media' => rand(100, 200),
	            'comment_status' => 'open',
	            'ping_status' => 'open',
	            'sticky' => false,
	            'template' => '',
	            'format' => 'standard',
	            'meta' => array(
	                'custom_meta_1' => rand(1000, 9999),
	                'custom_meta_2' => 'value ' . $i
	            ),
	            'categories' => array(rand(1, 5), rand(6, 10)),
	            'tags' => array(rand(11, 20), rand(21, 30), rand(31, 40)),
	            '_links' => array(
	                'self' => array(
	                    array('href' => 'https://example.com/wp-json/wp/v2/posts/' . $i)
	                ),
	                'collection' => array(
	                    array('href' => 'https://example.com/wp-json/wp/v2/posts')
	                ),
	                'about' => array(
	                    array('href' => 'https://example.com/wp-json/wp/v2/types/post')
	                ),
	                'author' => array(
	                    array('href' => 'https://example.com/wp-json/wp/v2/users/' . rand(1, 10))
	                ),
	                'replies' => array(
	                    array('href' => 'https://example.com/wp-json/wp/v2/comments?post=' . $i)
	                )
	            )
	        );
	    }
	    
	    // Encode and decode JSON repeatedly
	    for ($i = 0; $i < $repeat_times; $i++) {
	        // Encode to JSON
	        $json = json_encode($posts);
	        
	        // Decode back to PHP
	        $decoded = json_decode($json, true);
	        
	        // Process the data
	        $processed = array_map(function($post) {
	            $post['processed_title'] = strip_tags($post['title']['rendered']);
	            $post['word_count'] = str_word_count(strip_tags($post['content']['rendered']));
	            return $post;
	        }, $decoded);
	    }
	    
	    return true;
	}

	/**
	 * 10. Template Processing
	 * Tests CPU with template file operations similar to WordPress theme system
	 */
	function test_wordpress_template_processing() {
	    // Create mock template data
	    $template_hierarchy = array(
	        'single.php',
	        'single-post.php',
	        'single-post-{slug}.php',
	        'page.php',
	        'page-{slug}.php',
	        'page-{id}.php',
	        'category.php',
	        'category-{slug}.php',
	        'tag.php',
	        'tag-{slug}.php',
	        'author.php',
	        'author-{nicename}.php',
	        'date.php',
	        'archive.php',
	        'search.php',
	        'attachment.php',
	        'attachment-{mime-type}.php',
	        'index.php'
	    );
	    
	    // Create template parts
	    $template_parts = array(
	        'header' => "<header>\n  <div class=\"site-branding\">\n    <h1>{{site_title}}</h1>\n  </div>\n  <nav>{{menu}}</nav>\n</header>",
	        'footer' => "<footer>\n  <div class=\"site-info\">{{copyright}}</div>\n</footer>",
	        'content' => "<article id=\"post-{{id}}\" class=\"{{post_class}}\">\n  <header>\n    <h2>{{title}}</h2>\n  </header>\n  <div class=\"entry-content\">{{content}}</div>\n</article>",
	        'sidebar' => "<aside class=\"widget-area\">\n  <section class=\"widget\">{{widget_content}}</section>\n</aside>"
	    );
	    
	    // Create replacement variables
	    $replacements = array(
	        '{{site_title}}' => 'My WordPress Site',
	        '{{menu}}' => '<ul><li><a href="#">Home</a></li><li><a href="#">About</a></li><li><a href="#">Contact</a></li></ul>',
	        '{{copyright}}' => '© ' . date('Y') . ' My WordPress Site',
	        '{{id}}' => '123',
	        '{{post_class}}' => 'post-123 type-post status-publish',
	        '{{title}}' => 'Sample Post Title',
	        '{{content}}' => '<p>This is sample content for the post.</p>',
	        '{{widget_content}}' => '<h3>Recent Posts</h3><ul><li><a href="#">Post 1</a></li><li><a href="#">Post 2</a></li></ul>'
	    );
	    
	    // Simulate WordPress template loading and processing
	    for ($i = 0; $i < 200; $i++) {
	        // Select a random template type
	        $template_type = $template_hierarchy[array_rand($template_hierarchy)];
	        
	        // Get all template parts
	        $template = '';
	        foreach ($template_parts as $part) {
	            $template .= $part . "\n";
	        }
	        
	        // Process the template - replace variables
	        foreach ($replacements as $placeholder => $value) {
	            $template = str_replace($placeholder, $value, $template);
	        }
	        
	        // Process conditional logic (simplified)
	        if (strpos($template_type, 'single') === 0) {
	            $template = str_replace('{{is_single}}', 'true', $template);
	            $template = preg_replace('/\{\{if_single\}\}(.*?)\{\{\/if_single\}\}/s', '$1', $template);
	        } else {
	            $template = str_replace('{{is_single}}', 'false', $template);
	            $template = preg_replace('/\{\{if_single\}\}(.*?)\{\{\/if_single\}\}/s', '', $template);
	        }
	        
	        // Apply WordPress-like filters
	        $template = str_replace('&quot;', '"', $template);
	        $template = str_replace('&lt;', '<', $template);
	        $template = str_replace('&gt;', '>', $template);
	        $template = str_replace('&amp;', '&', $template);
	    }
	    
	    return true;
	}	



	function test_memory_array() {
		for($i=0;$i<100;$i++) {
			$data = array();

			for($j=0;$j<50;$j++) {
				$row=array();
				for($k=0;$k<40;$k++) {
					$s="";
					for($u=0;$u<30;$u++)
						$s.="ssdifjoi oiwejfoweijfwe ewifjewfefjewfijweoifjweofijewofijweofijewssdifjoi oiwejfoweijfwe ewifjewfefjewfijweoifjweofijewofijweofijewssdifjoi oiwejfoweijfwe ewifjewfefjewfijweoifjweofijewofijweofijewssdifjoi oiwejfoweijfwe ewifjewfefjewfijweoifjweofijewofijweofijewssdifjoi oiwejfoweijfwe ewifjewfefjewfijweoifjweofijewofijweofijew";
					$row[]=$s;					
				}

				$data[]=$row;
			}

			unset($data);
		}

		return true;
	}


	function test_cpu_memory() {
		$data=array();
		for ($i=0;$i<200000;$i++) {
			$data[$i]=rand(1,5000);
		}

		foreach($data as $dk=>$dv) {
			$data[$dk]=md5(serialize($dv));
		}

		for ($i=0;$i<5000;$i++) {
			$data[(rand(1,100000))] = md5(md5(rand(1,100000)));
		}

		for ($i=0;$i<100000;$i++) {
			$data[$i]=rand(1,5000);
		}

		foreach($data as $dk=>$dv) {
			$data[$dk]=md5(serialize($dv));
		}

		for ($i=0;$i<5000;$i++) {
			$data[(rand(1,100000))] = md5(md5(rand(1,100000)));
		}

		for ($i=0;$i<100000;$i++) {
			$data[$i]=rand(1,5000);
		}

		foreach($data as $dk=>$dv) {
			$data[$dk]=md5(serialize($dv));
		}

		for ($i=0;$i<5000;$i++) {
			$data[(rand(1,100000))] = md5(md5(rand(1,100000)));
		}

		return true;
	}


	function test_filewrite() {
	    $tmp_folder = $this->tmp_folder_name();
	    $chunkSize = 1024 * 1024; // 1MB per write
	    $fileSize = 50 * 1024 * 1024; // 50MB per file
	    $numFiles = 20; // 20 test files

	    $fn = $tmp_folder . "/tmp.filewrite";

	    // Generate a 1MB string using 1KB chunks
	    $oneMB_block = "";
	    for ($i = 0; $i < 1024; $i++) { 
	        $oneMB_block .= $this->get_1kb_text(); 
	    }

	    for ($i = 0; $i < $numFiles; $i++) {
	        if (file_exists($fn)) {
	            unlink($fn);
	        }

	        $fp = fopen($fn, "w");
	        if ($fp) {
	            $chunks = $fileSize / $chunkSize; // 50 writes per file

	            for ($k = 0; $k < $chunks; $k++) {
	                fwrite($fp, $oneMB_block);
	            }

	            fflush($fp);
	            fclose($fp);
	        }
	    }

	    return true;
	}

	function test_filewrite_old() {
		$tmp_folder = $this->tmp_folder_name();
		#$this->clean_tmp_folder();

		$generated_filenames = array();

		$write_content = "";
		#for($k=0;$k<20;$k++) {
			# make it 1mb
			for ($m=0;$m<1024;$m++) 
				$write_content .= $this->get_1kb_text();
		#}

		$fn = $tmp_folder."/tmp.filewrite";

		for($i=0;$i<20;$i++) {
			
			# $fn = $tmp_folder."/tmp.filewrite.".rand(1000,10000);
			# $generated_filenames[]=$fn;

			if (file_exists($fn)) {
				unlink($fn);
				clearstatcache();
			}

			$fp = fopen($fn, "w");

			# let's write 50mbytes
			for ($k=0;$k<50;$k++)
				fwrite($fp, $write_content);

			fflush($fp);
			fclose($fp);

			clearstatcache();
			#unlink($fn);


			# randomly delete some of the files, so we dont consume too much disk space
			#if (rand(0,2)==1) {
			#	$randomly_selected_file = $this->random_file_from_tmp();			
			#	if ($randomly_selected_file!="")
			#		unlink($randomly_selected_file);
			#}
		}

		unset($write_content);

		return true;
	}



	function test_filewrite_smallfiles() {
	    $tmp_folder = $this->tmp_folder_name();
	    $numFiles = 500; // Total files to create
	    $fileSize = 150 * 1024; // 150KB per file

	    // Generate 150KB block once using 1KB chunks
	    $write_content = "";
	    for ($m = 0; $m < 150; $m++) { 
	        $write_content .= $this->get_1kb_text(); 
	    }

	    for ($i = 0; $i < $numFiles; $i++) {
	        $fn = $tmp_folder . "/tmp.smallfile_" . $i; // Sequential naming

	        $fp = fopen($fn, "w");
	        if ($fp) {
	            fwrite($fp, $write_content); // Write 150KB in one call
	            fflush($fp);
	            fclose($fp);
	        }
	    }

	    return true;
	}
	function test_filewrite_smallfiles_old() {
		$tmp_folder = $this->tmp_folder_name();
		#$this->clean_tmp_folder();

		$generated_filenames = array();

		$write_content = "";
		# make it 150kb
		for ($m=0;$m<150;$m++) 
			$write_content .= $this->get_1kb_text();
		
		#$fn = $tmp_folder."/tmp.smallfile";

		for($i=0;$i<500;$i++) {
			
			# 
			$fn = $tmp_folder."/tmp.filewrite.".rand(1000,10000);
			#$generated_filenames[]=$fn;

			$fp = fopen($fn, "w");
			fwrite($fp, $write_content);
			fclose($fp);
			clearstatcache();
			
			# unlink($fn);
		}

		unset($write_content);

		return true;
	}


	function test_filecopy() {

		$tmp_folder = $this->tmp_folder_name();
		# $randomly_selected_file = $this->random_file_from_tmp();
		$source_fn = $tmp_folder."/tmp.filewrite";


		$copy_count = 20;

		if (!file_exists($source_fn)) {
			$write_content = "";
			#for($k=0;$k<50;$k++) {
				# make it 1mb
				for ($m=0;$m<1024;$m++) 
					$write_content .= $this->get_1kb_text();
			#}

			
			$fp = fopen($source_fn, "w");
			
			for($k=0;$k<50;$k++) {
				fwrite($fp, $write_content);
			}

			fclose($fp);

			$copy_count--;
		}


		

		for ($i=0;$i<$copy_count;$i++) {

			clearstatcache();
			$dest_fn = $tmp_folder."/tmp.filecopy.".rand(10000,99999);
			

			# find random file in temp folder
			#$randomly_selected_file = $this->random_file_from_tmp();
			#$file_content = file_get_contents($randomly_selected_file);
			#unset($file_content);

			# find random file in temp folder
			# $randomly_selected_file = $this->random_file_from_tmp();

			#if ($randomly_selected_file=="")
			#	throw new Exception("Failed to select test file from temporary folder!");
			
			copy($source_fn, $dest_fn);

			clearstatcache();
			unlink($dest_fn);
			
			#$randomly_selected_file = $this->random_file_from_tmp();			
			#if ($randomly_selected_file!="")
			#	unlink($randomly_selected_file);
		}

		return true;
	}


	function random_file_from_tmp() {
		$tmp_folder=$this->tmp_folder_name();

		if (!is_dir($tmp_folder)) {
			throw new Exception("Something went wrong, TMP folder does not exist. Please restart the benchmark!");
		}

		$tmp_files = array();
		if ($dh=opendir($tmp_folder)) {
			while(($file=readdir($dh))!==false) {
				if ($file!="." && $file!="..") {
					if (is_file($tmp_folder."/".$file))
						$tmp_files[]=$tmp_folder."/".$file;
				}
			}
			closedir($dh);
		}

		$found_files = count($tmp_files);
		if ($found_files==0)
			throw new Exception("Something went wrong, could not find any files in TMP folder. Please restart the benchmark!");

		return($tmp_files[rand(0,($found_files-1))]);
	}

	function tmp_folder_name() {
		# return(dirname(__FILE__)."/tmp");
		# some servers do not allow writing to plugin's folder.
		# that's why using upload folder instead
		$u = wp_upload_dir();
		return($u["basedir"]."/wpbenchmark");

	}
	function make_tmp_folder() {
		$tmp_folder = $this->tmp_folder_name();
		if (!is_dir($tmp_folder)) {
			if (!mkdir($tmp_folder))
				throw new Exception("Failed to create temporary folder, please check that PHP can create ".$tmp_folder."!");
		}
	}
	function clean_tmp_folder() {
		$tmp_folder=$this->tmp_folder_name();

		if (!is_dir($tmp_folder)) {
			$this->make_tmp_folder();
			return true;
		}
		
		if ($dh=opendir($tmp_folder)) {
			while(($file=readdir($dh))!==false) {
				if ($file!="." && $file!="..") {
					if (is_file($tmp_folder."/".$file))
						unlink($tmp_folder."/".$file);
				}
			}
			closedir($dh);
		}
	}

	function init_dbtable_names() {
		global $wpdb;
		$this->dbtables=$this->get_db_table_names();
	}

	function get_db_table_names() {
		global $wpdb;

		$tables = array();
		$tables["obj"] = $wpdb->prefix."wpbench_o";
		$tables["prop"] = $wpdb->prefix."wpbench_p";
		$tables["log"] = $wpdb->prefix."wpbench_l";		

		return($tables);
	}

	function clean_db_test_tables() {
		global $wpdb;

		$this->init_dbtable_names();

		# $dbtables = $this->get_db_table_names();
		foreach($this->dbtables as $dbt) {
			$wpdb->query("DROP TABLE IF EXISTS ".$dbt.";");
		}

		return true;
	}

	function create_db_test_tables() {
		global $wpdb;

		$this->init_dbtable_names();

		$sql = "CREATE TABLE IF NOT EXISTS `".$this->dbtables["obj"]."` (
			  `o_id` int unsigned NOT NULL,
			  `random_int` int unsigned NOT NULL,
			  `object_name` varchar(255) NOT NULL,
			  `object_type` enum('ocean','mountain','space','earth') NOT NULL,
			  `random_text` mediumtext
			) ;
		";
		$wpdb->query($sql);

		$sql = "ALTER TABLE `".$this->dbtables["obj"]."` ADD PRIMARY KEY (`o_id`), ADD KEY `object_type` (`object_type`);";
		$wpdb->query($sql);

		$sql = "ALTER TABLE `".$this->dbtables["obj"]."` MODIFY `o_id` int unsigned NOT NULL AUTO_INCREMENT;";
		$wpdb->query($sql);



		$sql = "CREATE TABLE IF NOT EXISTS `".$this->dbtables["prop"]."` (
		  `p_id` bigint unsigned NOT NULL,
		  `o_id` int unsigned NOT NULL,
		  `p_name` varchar(255) NOT NULL,
		  `p_data` mediumtext 
		) ;
		";
		$wpdb->query($sql);

		$sql = "ALTER TABLE `".$this->dbtables["prop"]."` ADD PRIMARY KEY (`p_id`), ADD KEY `o_id` (`o_id`);";
		$wpdb->query($sql);

		$sql = "ALTER TABLE `".$this->dbtables["prop"]."` MODIFY `p_id` bigint unsigned NOT NULL AUTO_INCREMENT;";
		$wpdb->query($sql);


		$sql = "
		CREATE TABLE IF NOT EXISTS `".$this->dbtables["log"]."` (
		  `l_id` bigint unsigned NOT NULL,
		  `o_id` int unsigned NOT NULL,
		  `p_id` bigint unsigned NOT NULL,
		  `txt` varchar(255) NOT NULL
		) ;";
		$wpdb->query($sql);
		
		$sql="ALTER TABLE `".$this->dbtables["log"]."` ADD PRIMARY KEY (`l_id`), ADD KEY `o_id` (`o_id`), ADD KEY `p_id` (`p_id`), ADD KEY `o_id_2` (`o_id`,`p_id`);";
		$wpdb->query($sql);

		$sql="ALTER TABLE `".$this->dbtables["log"]."` MODIFY `l_id` bigint unsigned NOT NULL AUTO_INCREMENT;";
		$wpdb->query($sql);

		return true;
	}

	function insert_into_db_testlog($txt, $o_id, $p_id=0) {
		global $wpdb;

		$wpdb->insert($this->dbtables["log"], array(
				"o_id"=>$o_id,
				"p_id"=>$p_id,
				"txt"=>$txt
			)
		);

		return true;
	}

	function test_db_insert() {
		global $wpdb;

		$this->init_dbtable_names();

		$this->clean_db_test_tables();

		# create test tables
		$this->create_db_test_tables();

		$object_types = $this->dbtest_object_types;
		$object_properties = $this->dbtest_object_properties;


		# generate 10 random strings to use - each 30Kb
		$random_data = array();
		$random_binary = "";
		for ($j=0;$j<10;$j++) {
			$random_binary = "";
			
			for($r=0;$r<30;$r++) {
				$random_binary.=$this->random_string(1024);
			}
			$random_data[$j]=$random_binary;
		}

		$next_o_type = 0;

		for($o=1;$o<=500;$o++) {
			$wpdb->insert(
				$this->dbtables["obj"],
				array(
					"random_int"=>rand(1,1000),
					"object_name"=>"Object ".$o,
					"object_type"=>$object_types[$next_o_type],
					"random_text"=>$random_binary[rand(0,9)]
				)
			);
			$o_id = $wpdb->insert_id;

			$this->insert_into_db_testlog("Created new object, nr ".$o, $o_id);

			foreach($object_properties as $p) {
				$wpdb->insert(
					$this->dbtables["prop"],
					array(
						"o_id"=>$o_id,
						"p_name"=>$p
					)
				);
				$p_id = $wpdb->insert_id;

				$this->insert_into_db_testlog("Created property ".$p." for o_id=".$o_id, $o_id, $p_id);

				if ($p=="name") {

					$tmp_data = $wpdb->get_results("select SQL_NO_CACHE object_name from ".$this->dbtables["obj"]." where o_id=".$o_id.";", ARRAY_A );

					if (count($tmp_data)>0) {
						$set_value = $tmp_data[0]["object_name"];
					} else {
						$set_value = "uknown name";
					}

				} else if ($p=="data1" || $p=="data2") {
					
					$set_value = $random_data[($o%10)];

				} else {
					$set_value = rand(10,1000);
				}

				$wpdb->update($this->dbtables["prop"], array("p_data"=>$set_value), array("p_id"=>$p_id));
				$this->insert_into_db_testlog("Updated property ".$p." with a value", $o_id, $p_id);

				unset($set_value);
				unset($tmp_data);
			}

			$next_o_type++;
			if ($next_o_type>3)
				$next_o_type=0;
		}

		return true;
	}


	function test_db_simple() {
		global $wpdb;
		$this->init_dbtable_names();

		for ($i=0;$i<400;$i++) {
			#$random_data = $wpdb->get_results("select * from ".$this->dbtables["obj"]." where random_int>=10 and random_int<=990 order by RAND() limit 600;", ARRAY_A );
			$random_data = $wpdb->get_results("select SQL_NO_CACHE * from ".$this->dbtables["obj"]." where random_int=".rand(1,1000)." order by RAND();", ARRAY_A );
			foreach($random_data as $r) {
				$o_properties = $wpdb->get_results("select SQL_NO_CACHE * from ".$this->dbtables["prop"]." where o_id=".$r["o_id"].";", ARRAY_A );						
			}
			foreach($random_data as $r) {
				$o_properties = $wpdb->get_results("select SQL_NO_CACHE * from ".$this->dbtables["prop"]." where o_id=".$r["o_id"].";", ARRAY_A );						
			}
		}

		# now let's do something about query cache
		$full_data = $wpdb->get_results("select SQL_NO_CACHE * from ".$this->dbtables["obj"]." order by RAND()", ARRAY_A );
		foreach($full_data as $r) {
			$wpdb->query("delete from ".$this->dbtables["prop"]." where o_id=".$r["o_id"]." and p_name='data2';");			
			$this->insert_into_db_testlog("Deleted data2 property for object ".$r["o_id"], $r["o_id"]);

			$o_properties = $wpdb->get_results("select SQL_NO_CACHE * from ".$this->dbtables["prop"]." where o_id=".$r["o_id"].";", ARRAY_A );						

			foreach($o_properties as $p) {
				if ($p["p_name"]=="size") {
					$wpdb->query("delete from ".$this->dbtables["log"]." where p_id=".$p["p_id"].";");
					$wpdb->update($this->dbtables["prop"], array("p_data"=>rand(200,2000)), array("p_id"=>$p["p_id"]));
					$this->insert_into_db_testlog("Update data2 property for object ".$r["o_id"], $r["o_id"], $p["p_id"]);
				}
			}

			$tmp_properties = $wpdb->get_results("select SQL_NO_CACHE * from ".$this->dbtables["prop"]." where o_id=".$r["o_id"].";", ARRAY_A );	

			$wpdb->update($this->dbtables["log"], array("txt"=>"Reset this value for testing.. Thank you for checking this out!"), array("o_id"=>$r["o_id"]));

		}

		return true;
	}


	function test_db_joins() {
		global $wpdb;

		$this->init_dbtable_names();

		
		$debug_mail_sent = false;

				
		for ($i=0;$i<50;$i++) {
			#$wpdb->get_results("select ".$this->dbtables["obj"].".o_id, ".$this->dbtables["prop"].".p_name, ".$this->dbtables["log"].".txt from ".$this->dbtables["obj"]." left join ".$this->dbtables["prop"]." on ".$this->dbtables["obj"].".o_id=".$this->dbtables["prop"].".o_id left join ".$this->dbtables["log"]." on ".$this->dbtables["prop"].".p_id=".$this->dbtables["log"].".p_id where ".$this->dbtables["obj"].".o_id=".rand(1,999)." and ".$this->dbtables["prop"].".p_name='name'");


			
			$sql = "select SQL_NO_CACHE ".$this->dbtables["obj"].".o_id, ".$this->dbtables["prop"].".p_data, MD5(CONCAT(" . $this->dbtables["prop"].".p_id, ".$this->dbtables["prop"].".p_name, ' : ', ".$this->dbtables["prop"].".p_data)) as calculated, ".$this->dbtables["log"].".txt from ".$this->dbtables["obj"]." left join ".$this->dbtables["prop"]." on ".$this->dbtables["obj"].".o_id=".$this->dbtables["prop"].".o_id left join ".$this->dbtables["log"]." on ".$this->dbtables["prop"].".p_id=".$this->dbtables["log"].".p_id where ".$this->dbtables["obj"].".object_type like '". $this->dbtest_object_types[(rand(0, (count($this->dbtest_object_types)-1)))]."' and (".$this->dbtables["prop"].".p_name='data1') order by RAND() limit 5;";
			$sql_result = $wpdb->get_results($sql);

			if (!$debug_mail_sent) {
				$debug_mail_sent = true;
			}


			for ($j=0;$j<50;$j++) {
				$id_from = ($j*10)+1;
				$id_to   = (($j+1)*10);

				$sql = "select SQL_NO_CACHE * from ".$this->dbtables["log"]." where o_id in (select o_id from ".$this->dbtables["obj"]." where o_id>=".$id_from." and o_id<=".$id_to." order by RAND()) order by RAND() limit 5;";
				$sql_result = $wpdb->get_results($sql, ARRAY_A);
				foreach($sql_result as $r) {
					$sql_result_2 = $wpdb->get_results("select SQL_NO_CACHE * from ".$this->dbtables["obj"]." where o_id=".$r["o_id"], ARRAY_A);
				}
			}

		}


		$debug_mail_sent = false;

		for ($i=0;$i<50;$i++) {
			$sql = "select SQL_NO_CACHE ".$this->dbtables["prop"].".p_id, ".$this->dbtables["prop"].".p_name, ".$this->dbtables["log"].".txt from ".$this->dbtables["log"]." left join ".$this->dbtables["prop"]." on ".$this->dbtables["log"].".p_id=".$this->dbtables["prop"].".p_id where ".$this->dbtables["prop"].".p_data like '%".$i."%' order by ".$this->dbtables["log"].".txt, ".$this->dbtables["prop"].".p_id desc limit 50;";
			$sql_result = $wpdb->get_results($sql);

			if (!$debug_mail_sent) {
				$debug_mail_sent = true;
			}
		}

		return true;

	}


	function test_network_download() {
		$tmp_folder = $this->tmp_folder_name();

		$test_filename = "download_test.jpg";
		$destination_filename = $tmp_folder."/".$test_filename;

		$download_args = array(
			"stream"=>true,
			"filename"=>$tmp_folder."/".$test_filename
		);

		for($i=0;$i<5;$i++) {
			if (file_exists($tmp_folder."/".$test_filename))
				unlink($tmp_folder."/".$test_filename);

			wp_remote_get("https://bandwidth-test.wpbenchmark.io/".$test_filename, $download_args);
		}


		if (file_exists($tmp_folder."/".$test_filename))
			unlink($tmp_folder."/".$test_filename);

		return true;
	}

	function get_1kb_text() {
		return("oooOoOoOOOOOOooooOoooOooOOoOOOooOOoooOOoOoOOoooOOOoOOoOoOoOooOOOOOoooooooOOoOOOoooOOoooooOOOooOoooOOoOoOooOOooOoOOOOoOOooooOoooOoOOOOOOooOOooooOoOoOooOOOOoOoOooOoOooooOooOOOoOOOoOOoooOOooOooOOooOOoooOooOoOOoOOOOOOoOooOoOOOoOOoOOoOoOooooOOOoOoOoOoOooooOoOOooOOoooOoOoooOoOOOoOooooOooOOoOooOOOoOOOooOOOOOOOOoooooOoOOoOooOOoOoooooOooooooOooooOOooooOOoOooooooOooOoOOoOOooOooOOoOooOoooOoOoOOoOOOooOooooOOoOoOooooOooOoOoOOoOoOoOooOOOooOOoooOoooooOooOoOoOoOOoooOOOoOOooOOooOoOOOoOOOooOooooOOOOoOooOOooOoOOooooOooOOOoOoooOOooOOoOooOooOOoOOOooOoooOOoOoOOOoOoOOOOoOoOOOOoooooOoOOOooOOoOoOoOOOOooooOooOOooooOooOoooooooOOooooOooOooOoOoOooooooOOooOOOooooOooooOOooOOOOOOoOooOOOoOOoOooOoOOOOOoOoOoOooOOOOOOoOoOOOoooOooOoOoOOOoOOOOOoooooOoOoOOooooooOoOoOOOoOooOooooOOOooooOoOOoOOOoOoOoOoOooOOOooOoOooOoOOOooOoOoOoOOOOOoOOoOoOoOooOoOoOOOOoOOOOoOoooOoOOOOooOOOoOOOOOOOOooOooOOOooOooooOooOooOOoOooOOooOOOOoOOoOOOooOoOoOOOOOoooOoOoOoOoooooooOOOOooOOOOooOOOOoOooooOOooOoOoOoOoOOoOoooooooOOOoOOoOOooOoooOooOOOooOOoOoooooOoOoooOooOOooOoOOOoooOOOoO");
	}


	function local_wp_cache_flush() {
		# removed 21.05.2023 # we should only clear OUR records, and not EVERYTHING # wp_cache_flush();
		
		if (function_exists("wp_cache_supports")) {
			if (wp_cache_supports("flush_group")) {
				for ($j=0;$j<$this->object_cache_group_count;$j++) {
					wp_cache_flush_group("wpbenchmark-".$j);
				}
			} else {
				wp_cache_flush();
			}
		} else {
			wp_cache_flush();
		}

		return true;
	}

	function local_add_test_variable() {
		wp_cache_add("is_this_persistent", "1", "wpbenchmark", 360);
		return true;
	}



	function get_use_data() {
		$use_data = array();

		$use_data[0] = "123";
		$use_data[1] = "Lorem ipsum dolor sit amet, consectetur adipiscing elit.";
		$use_data[2] = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed faucibus tempor ex et tincidunt. Duis mollis risus vel congue viverra.";
		$use_data[3] = "1";
		$use_data[4] = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed faucibus tempor ex et tincidunt. Duis mollis risus vel congue viverra. Mauris interdum porta ex, et rutrum nulla euismod tincidunt. Ut tincidunt lorem eu libero vehicula, nec bibendum risus condimentum. Vestibulum mi felis, ornare vitae velit sit amet, dictum varius purus. Nam blandit suscipit semper. Nam sem arcu, aliquam quis tellus in, gravida ullamcorper eros.
		Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed faucibus tempor ex et tincidunt. Duis mollis risus vel congue viverra. Mauris interdum porta ex, et rutrum nulla euismod tincidunt. Ut tincidunt lorem eu libero vehicula, nec bibendum risus condimentum. Vestibulum mi felis, ornare vitae velit sit amet, dictum varius purus. Nam blandit suscipit semper. Nam sem arcu, aliquam quis tellus in, gravida ullamcorper eros.";
		$use_data[5] = "Nam sem arcu, aliquam quis tellus in, gravida ullamcorper eros.";
		$use_data[6] = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed faucibus tempor ex et tincidunt. Duis mollis risus vel congue viverra. Mauris interdum porta ex, et rutrum nulla euismod tincidunt. Ut tincidunt lorem eu libero vehicula, nec bibendum risus condimentum. Vestibulum mi felis, ornare vitae velit sit amet, dictum varius purus. Nam blandit suscipit semper. Nam sem arcu, aliquam quis tellus in, gravida ullamcorper eros.

Nullam vel odio pretium, sodales diam at, malesuada magna. Fusce nec malesuada elit. Cras ultricies ipsum sed mollis tristique. Nulla egestas eu ligula vitae faucibus. Nulla facilisi. Pellentesque convallis nunc volutpat arcu pretium, et sollicitudin augue tincidunt. In posuere urna orci, et sodales metus porttitor sed. Curabitur lacinia bibendum diam nec cursus. Suspendisse sollicitudin ipsum diam, ac varius risus dictum at. Fusce semper urna vel magna lacinia condimentum. Nulla eget consectetur nulla. Nunc a turpis sollicitudin, suscipit nisi eget, bibendum massa. Donec et sem magna. Donec ultrices dignissim velit. Etiam eros massa, molestie nec felis et, tristique tincidunt mauris.

Quisque condimentum elementum eros a venenatis. Aliquam suscipit ex sit amet eros bibendum consectetur. Pellentesque ut vulputate felis, et euismod urna. Cras maximus rutrum imperdiet. Integer suscipit pretium suscipit. Morbi luctus est lorem, eu eleifend nulla dignissim sit amet. Ut rutrum, elit sit amet iaculis luctus, metus turpis imperdiet ex, quis tempor turpis est ut nisl. Quisque suscipit, est id vulputate placerat, ante dui venenatis velit, at aliquam ipsum lorem vitae felis. Vivamus elit sapien, pellentesque mattis viverra sed, bibendum ac lectus. Nullam scelerisque lectus eget malesuada bibendum. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Donec sagittis est non sollicitudin elementum.

Vivamus et tellus odio. Nullam gravida cursus aliquet. Aenean ornare fringilla ex vitae pretium. Vestibulum sagittis sed turpis et bibendum. Phasellus ac augue vitae orci mollis placerat eu sed elit. Vestibulum porta enim nec ultrices semper. Orci varius natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Quisque diam quam, pellentesque eu interdum ut, vestibulum posuere lacus. Morbi in dui libero. Duis eros lorem, rutrum vel tempor vitae, congue mattis ante. Aenean at ex ut erat faucibus bibendum.";
		$use_data[7] = "87";
		$use_data[8] = "2";
		$use_data[9] = "3479813 193132942 137361341  1271283d";


		return($use_data);
	}




	# this function will just tell if this installation has persistent object or not.
	# DEPRECATED
	function test_has_persistent_oc() {
		# explanation: we try to get value from object cache, that we set in "cleanup_function" for previous test.
		# if we get the value, then persistent cache exists and we add tiny 1s sleep
		# otherwise we will sleep for 10 seconds to get lower score.

		
		$test_value = wp_cache_get("is_this_persistent", "wpbenchmark");
		if ($test_value==1)
			usleep(500);
		else
			return false;
			#sleep(10);
		
		return true;
	}

	# rough function - if we think persistent cache does not exist, we will throw error
	function must_have_persistent_cache() {
		$test_value = wp_cache_get("is_this_persistent", "wpbenchmark");
		if ($test_value==1) {
			return(true);
		} else {
			return(false);
			# 503 Service Unavailable
			http_response_code(503);
			die('<h2>503 Service Temporarily Unavailable - Wordpress persistent cache unavailable</h2>');
			# header('HTTP/1.1 503 Service Temporarily Unavailable');
			# header('Status: 503 Service Temporarily Unavailable');
		}
	}



	function fill_object_cache() {

		$big_array = array();
		$use_data  = $this->get_use_data();
		$use_data_size = count($use_data);

		# quick multidimensional array preparation
		for ($i=0;$i<15;$i++)  {
			$big_array[$i]=array();

			for($j=0;$j<5;$j++)
				$big_array[$i][$j]=array();
		}


		for ($j=0;$j<$this->object_cache_group_count;$j++) {
			
			# removed 21.may.2023 #$big_array = array();
			# removed 21.may.2023 #for($k=0;$k<100;$k++) {
			# removed 21.may.2023 #	$big_array[$k]=array();
			# removed 21.may.2023 #	for($n=0;$n<10;$n++) {
			# removed 21.may.2023 #		$big_array[$k][$n]=array();
			# removed 21.may.2023 #	}
			# removed 21.may.2023 #}

			for ($i=0;$i<$this->object_cache_key_count;$i++) {
				$this_data = $use_data[(($i+$j)%$use_data_size)];

				wp_cache_add("temp_".$i, $this_data, "wpbenchmark-".$j);

				# fill up big data array's random element 
				# removed 21.may.2023 # $big_array[rand(0,99)][rand(0,9)] = $this_data;
			}

			# removed 21.may.2023 # wp_cache_add("temp_big", $big_array, "wpbenchmark-".$j);
		} # end of group J

		return true;
	}



	function test_oc_persistent_write() {

		if (!$this->must_have_persistent_cache())
			return false;

		
		$this->fill_object_cache();

		# before 21.may.2023 we were flushing all cache and were filling it up 3 times.
		# cache flush would destroy ALL cache data. and was causing writes and deletes a lot
		# if we are testing writes - lets just try to fill same data several times. 
		$this->fill_object_cache();
		$this->fill_object_cache();

		# let's repeat it!
		# these 2 functions are included in fill_object_cache
		# removed 21.may.2023 # $this->local_wp_cache_flush();	# empty cache
		# removed 21.may.2023 # $this->local_add_test_variable(); # add very important variable, that we are checking

		# removed 21.may.2023 # $this->fill_object_cache();

		# let's repeat it!
		# 
		# removed 21.may.2023 # $this->local_wp_cache_flush();	# empty cache
		# removed 21.may.2023 # $this->local_add_test_variable(); # add very important variable, that we are checking

		# removed 21.may.2023 # $this->fill_object_cache();

		return true;

	} # end for persistent_write()

	

	function test_oc_persistent_read() {

		if (!$this->must_have_persistent_cache())
			return false;

		$multiple_keys_to_get = array();
		$max_object_cache_group = $this->object_cache_group_count-1; # because rand() is inclusive and group are from 0 to 9 = 10 groups.
		#$max_object_key_count = floor(($this->object_cache_key_count-1)*1.1); # we add 10% of non-existing keys.
		$max_object_key_count = $this->object_cache_key_count-1; # TEST - do not add 10%


		if (function_exists("wp_cache_supports")) {
			if (wp_cache_supports("get_multiple"))
				$test_get_multiple = true;
			else
				$test_get_multiple = false;
		} else {
			$test_get_multiple = false;
		}

		for ($i=0;$i<1500000;$i++) {

			if ($i%100==0 && $test_get_multiple) {
				$multiple_data = wp_cache_get_multiple($multiple_keys_to_get, "wpbenchmark-".rand(0,$max_object_cache_group));

				unset($multiple_data);
				$multiple_keys_to_get=array();

			} else if ($i%50==0) {
				$test_value = wp_cache_get( 'alloptions', 'options' );
			} else if ($i%5==0) {
				# instead of getting value from cache - we add key to get it with get_multiple()
				$multiple_keys_to_get[] = "temp_".rand(0,$max_object_key_count);
			} else {
				$test_value = wp_cache_get("temp_".rand(0,$max_object_key_count), "wpbenchmark-".rand(0,$max_object_cache_group));
			}

			
		}


		return true;
	}

	
	function test_oc_persistent_mixed() {

		if (!$this->must_have_persistent_cache())
			return false;

		$max_object_cache_group = $this->object_cache_group_count-1; # because rand() is inclusive and group are from 0 to 9 = 10 groups.
		#$max_object_key_count = floor(($this->object_cache_key_count-1)*1.1); # we add 10% of non-existing keys.
		$max_object_key_count = $this->object_cache_key_count-1; # we add 10% of non-existing keys.

		$use_data = $this->get_use_data();
		$use_data_size = count($use_data);

		if (function_exists("wp_cache_supports"))
			$test_get_multiple = wp_cache_supports("get_multiple");
		else
			$test_get_multiple = false;

		# 29.jan - changed from 1'000'000  to 2'000'000 - doubling
		for($i=0;$i<300000;$i++) {
			if ($i%200==0) {
				wp_cache_delete("temp_".rand(0,$max_object_key_count), "wpbenchmark-".rand(0,$max_object_cache_group));
			} else if ($i%40==0) {
				$random_key = rand(0,$max_object_key_count);
				$test_value = $use_data[$i%$use_data_size];
				wp_cache_set("temp_".$random_key, $test_value, "wpbenchmark-".rand(0,$max_object_cache_group));
			} else if ($i%100==0) {
				# removed 21.05.2023 # if (rand(0,1)==0)
				# removed 21.05.2023 #	$value = wp_cache_get("temp_big", "wpbenchmark-".rand(0,$max_object_cache_group));
				# removed 21.05.2023 # else
				$value = wp_cache_get( 'alloptions', 'options' );

				# small memory cleanup :)
				unset($value);
			} else {

				if (rand(0,5)==1) {
					# every 5 request - try to get_multiple, if supported
					if ($test_get_multiple) {
						$multiple_keys_to_get = array();

						for ($q=0;$q<3;$q++)
							$multiple_keys_to_get[] = rand(0,$max_object_key_count);

						$multiple_data = wp_cache_get_multiple($multiple_keys_to_get, "wpbenchmark-".rand(0,$max_object_cache_group));
					} else {
						# get_multiple is not supported, instead we do 3 simple get-requests
						$random_key = rand(0,$max_object_key_count);
						$test_value = wp_cache_get("temp_".$random_key, "wpbenchmark-".rand(0,$max_object_cache_group));
						$random_key = rand(0,$max_object_key_count);
						$test_value = wp_cache_get("temp_".$random_key, "wpbenchmark-".rand(0,$max_object_cache_group));
						$random_key = rand(0,$max_object_key_count);
						$test_value = wp_cache_get("temp_".$random_key, "wpbenchmark-".rand(0,$max_object_cache_group));
					}
				} else {
					$random_key = rand(0,$max_object_key_count);
					$test_value = wp_cache_get("temp_".$random_key, "wpbenchmark-".rand(0,$max_object_cache_group));
				}
			}				
			
		} # end for


		return true;
	} # end function


} # end class
