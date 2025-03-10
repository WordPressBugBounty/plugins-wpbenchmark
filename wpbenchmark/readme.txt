=== WordPress Hosting Benchmark tool ===
Contributors: anton.aleksandrov
Plugin URI: https://wpbenchmark.io/
Donate link: https://wpbenchmark.io/donate/
Tags: benchmark, speed, hosting, performance, optimization
Requires at least: 4.0
Tested up to: 6.7
Requires PHP: 5.6
Stable tag: 1.6.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Benchmark your hosting server CPU, memory and disk, compare with others.

== Description ==

This plugin empowers you to thoroughly evaluate your WordPress hosting server's performance with detailed, objective metrics. Simple and free to use - our mission is to help regular WordPress users determine if they're actually getting the hosting quality they've paid for.
## Comprehensive Testing Suite
*   **CPU & Processing Power:** Tests both raw processing speed and WordPress-specific functions
*   **Memory Usage & Efficiency:** Evaluates how effectively your server handles memory-intensive operations
*   **Filesystem Performance:** Measures file read/write capabilities critical for WordPress
*   **Database Operations:** Tests query execution speed and complex joins
*   **WordPress Object Cache:** Evaluates persistent cache performance
*   **Network Speed:** Measures connection responsiveness
## Advanced Features
*   **Scheduled Benchmarking:** Automatically run tests at regular intervals to monitor your hosting performance over time and identify patterns or degradation
*   **WordPress Core Function Tests:** Specifically tests WordPress operations like shortcode processing, hook execution, and transient handling
*   **Comparative Analysis:** See how your hosting stacks up against others with similar configurations
*   **Performance Scoring:** Get an objective rating that helps you understand your server's capabilities
## Easy to Use
No technical expertise or additional software required! All tests run directly within WordPress, using your existing PHP installation. Simply install the plugin, click to run benchmarks, and get detailed results you can understand.
## Make Informed Decisions
Whether you're troubleshooting slow site performance, evaluating a new hosting provider, or simply curious about your current hosting quality, this tool provides the concrete data you need to make smart decisions about your WordPress hosting.
Don't settle for underwhelming hosting performance. Use WordPress Hosting Benchmark Tool today to discover exactly what you're getting from your hosting provider.



== Frequently Asked Questions ==
Q: What is being tested?
A: CPU, memory bandwidth, disk speed, persistent object cache, network download speed.

Q: Which parameter is the most important?
A: CPU. Wordpress speed depends on fast CPU and in particular - CPU generation and speed (GHz). PHP version of your website also matters - newer PHP versions tend to do the same job faster.

Q: What should be our considerations, before using this plugin?
A: Plugin will generate large temporary files and run a lot SQL queries. You should make sure, your hosting has at least 500MB of free disk space and that your database server allows large number of queries. All temporary files and database tables will be deleted after benchmark is complete.

== Installation ==

Please use default Wordpress plugin installation method through Wordpress plugin repository.


== Screenshots ==

1. Bechmark results inside Wordpress interface
2. Detailed benchmark results
3. Backward connectivity test results and timings

== Changelog ==

1.6.1  - small fix of missing attribute of an array.

1.6.0  - New section: Benchmark using Wordpress core functions. Optimized filesystem benchmarks to reduce CPU and memory overhead.

1.5.1  - small CSS fix and typo correction, thanks Jim!

1.5.0  - introducing ability to schedule benchmarks, performed at the background. CPU only.

1.4.7  - bug in SQL queries fix

1.4.2  - small typo and Wordpress compatability update.

1.4.1  - now using temporary folder under upload folder, as some hosts do not allow writing to plugin's folder.

1.4.0  - added 2 purely mathematical CPU intense benchmarks. Added SQL_NO_CACHE to database tests to avoid using query cache.

1.3.8  - fixed permission bug, that allowed non-admin users to execute plugin. Now only admin will have access to bechmarks.

1.3.7  - fixed CSRF bug and WP nonce check vulnerability reported by patchstack.com, Dhabaleshwar Das.

1.3.6  - temporary table definition fix, extra check for wp_cache_supports function, as several people reported a problem.

1.3.3  - improved object cache benchmark tests.

1.3.2  - fixed multisite support. 

1.3.1  - minor fix for posted data evaluation, that was accidently messing with media uploads.

1.3    - privacy. Now you can opt to not have stats displayed through your results code page or expire these results after certain time. And a few minor visual changes have also been added. 

1.2    - added Wordpress load timing tests. We will try to access your site several times while benchmark test are running and a momement after. For now all tests are executed from server located in Germany. You can see these results, when you click Read More link.

1.1.4  - fixed in object cache testing.

1.1.3  - small internal link fix.

1.1.2  - added option to skip persistent object cache.

1.1.0  - added persisten object cache testing, tuned MySQL and CPU benchmarks.

1.0.1  - disk benchmark fix, added workaround to skip failed tests.

0.9.3 - small adjustements on filesystem tests

0.9  - reviewed testing policy, several tests have been made lighter, so those can now be run at servers with restrictions.

0.8  - removed force selection of MyISAM for database tests. Now the default database engine will be used.

0.7  - added option to run each test just once, instead of 5 times. + minor fixes

0.6  - minor fix and Wordpress version compatability update

0.5  - added history of executed benchmarks

0.4  - minor polishing and WP version compatability check

0.3  - completely rewritten filesystem benchmark tests.

0.23 - small fix in filesystem benchmark tests.

0.22 - small fixes for really low resource servers.

0.21 - added new test - filesystem benchmark writing many small files, adjusted CPU tests

0.2 - Small changes in test routines

0.1 - Initial version
