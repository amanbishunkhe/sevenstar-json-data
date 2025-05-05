<?php

/**
 * Wp Insert Post
 *
 */

/**
 * Handles API data loading.
 *
 */
class Wp_Insert_Post
{
    /**
     * API URL
     *
     * @var string
     */
    private $url = 'https://raw.githubusercontent.com/amanbishunkhe/sevenstar-json-data/refs/heads/master/output.json';

    /**
     * Option name for storing file size.
     *
     * @var string
     */
    private $file_size_option = 'sevenstar_api_file_size';

    /**
     * Option name for storing last processed index.
     *
     * @var string
     */
    private $last_processed_option = 'sevenstar_last_processed_index';

    /**
     * Batch size for processing posts.
     *
     * @var int
     */
    private $batch_size = 5;

    /**
     * The final data.
     *
     * @access protected
     * @var string
     */
    protected $data;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->setup_hooks();
    }

    /**
     * Setup hooks.
     */
    protected function setup_hooks()
    {
        add_action('wp_loaded', [$this, 'init_cron_job']);
        add_action('sevenstar_process_posts_batch', [$this, 'process_posts_batch']);

        // Add custom cron schedule
        add_filter('cron_schedules', [$this, 'add_custom_cron_schedule']);
    }

    /**
     * Add custom cron schedule for 5 minutes
     */
    public function add_custom_cron_schedule($schedules)
    {
        $schedules['five_minutes'] = array(
            'interval' => 5 * 60, // 5 minutes in seconds
            'display'  => __('Every 5 Minutes'),
        );
        return $schedules;
    }

    /**
     * Initialize the cron job.
     */
    public function init_cron_job()
    {
        if (!wp_next_scheduled('sevenstar_process_posts_batch')) {
            wp_schedule_event(time(), 'five_minutes', 'sevenstar_process_posts_batch');
        }
    }

    /**
     * Process posts in batches.
     */
    public function process_posts_batch()
    {
        // Get the current file size from the remote source.
        $current_file_size = $this->get_remote_file_size();

        if ($current_file_size === false) {
            error_log('Failed to retrieve remote file size. Skipping fetch.');
            return;
        }

        // Retrieve the previously stored file size from the database.
        $stored_file_size = get_option($this->file_size_option, 0);

        // If the file size has changed, reset the last processed index
        if ($current_file_size != $stored_file_size) {
            update_option($this->file_size_option, $current_file_size);
            delete_option($this->last_processed_option);
        }

        // Fetch data if not already loaded
        if (empty($this->data)) {
            $this->data = $this->get_remote_url_contents();
            if (empty($this->data)) {
                error_log('No data retrieved from remote URL.');
                return;
            }
        }

        // Get the last processed index
        $last_processed = get_option($this->last_processed_option, 0);
        $total_posts = count($this->data);

        // Calculate the batch range
        $start = $last_processed;
        $end = min($start + $this->batch_size, $total_posts);

        // Process the current batch
        for ($i = $start; $i < $end; $i++) {
            if (isset($this->data[$i])) {
                $this->insert_post($this->data[$i]);
            }
        }

        // Update the last processed index
        if ($end >= $total_posts) {
            // Reset if we've processed all posts
            delete_option($this->last_processed_option);
            error_log('All posts processed. Batch processing complete.');
        } else {
            update_option($this->last_processed_option, $end);
            error_log(sprintf('Processed batch %d-%d of %d posts.', $start, $end, $total_posts));
        }
    }

    /**
     * Get the size of the remote file.
     *
     * @return int|false File size in bytes, or false on failure.
     */
    protected function get_remote_file_size()
    {
        $response = wp_remote_head($this->url);
        if (is_wp_error($response)) {
            error_log('Failed to get remote file size: ' . $response->get_error_message());
            return false;
        }
        return isset($response['headers']['content-length']) ? (int) $response['headers']['content-length'] : false;
    }

    /**
     * Get remote file contents.
     *
     * @access private
     * @return string Returns the remote URL contents.
     */
    private function get_remote_url_contents()
    {
        if (is_callable('network_home_url')) {
            $site_url = network_home_url('', 'http');
        } else {
            $site_url = get_bloginfo('url');
        }
        $site_url = preg_replace('/^https/', 'http', $site_url);
        $site_url = preg_replace('|/$|', '', $site_url);
        $args = array(
            'site' => $site_url,
        );

        // Get the response.
        $api_url  = add_query_arg($args, $this->url);

        $response = wp_remote_get(
            $api_url,
            array(
                'timeout' => 20,
            )
        );
        // Early exit if there was an error.
        if (is_wp_error($response)) {
            return '';
        }

        // Get the CSS from our response.
        $contents = wp_remote_retrieve_body($response);

        if (is_wp_error($contents)) {
            error_log('Error retrieving remote content.');
            return [];
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decoding error: ' . json_last_error_msg());
            return [];
        }

        return $data;
    }

    /**
     * Insert or update a post with a specific post ID.
     *
     * @param array $data Post data array.
     */
    private function insert_post($data)
    {
        // Ensure required WordPress functions are loaded.
        if (!function_exists('wp_insert_post')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        // Check if the post already exists.
        if (!function_exists('post_exists')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        // Validate required fields.
        if (empty($data['id']) || empty($data['post_title'])) {
            error_log('Missing ID and Title in item.');
            return;
        }

        $content = '';
        if (!empty($data['details'])) {
            $content .= wp_kses_post($data['details']);
        }

        $post_args = [
            'post_title'   => sanitize_text_field($data['post_title']),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_date'    => !empty($data['created_at']) ? date('Y-m-d H:i:s', strtotime($data['created_at'])) : current_time('mysql'),
        ];

        // Add custom slug if provided
        if (!empty($data['slug'])) {
            $post_args['post_name'] = $this->generate_unique_slug(
                sanitize_title($data['slug']),
                isset($data['id']) ? (int)$data['id'] : 0
            );
        }

        $existing_post_id = post_exists($post_args['post_title'], '', '', 'post');

        if ($existing_post_id) {
            $post_args['ID'] = $existing_post_id;
            $post_id = wp_update_post($post_args);
            error_log('Post updated with ID: ' . $existing_post_id);
        } else {
            $post_args['import_id'] = (int) $data['id'];
            $post_id = wp_insert_post($post_args);

            if (is_wp_error($post_id)) {
                error_log('Error inserting post: ' . $post_id->get_error_message());
                return;
            }
            error_log('Post inserted with ID: ' . $post_id);
        }

        // Set featured image if available
        if (!empty($data['image_source'])) {
            $this->set_featured_image($post_id, $data['image_source']);
        }

        //$this->handle_terms($post_id, $data);
    }

    /**
     * Generate a unique slug for the post
     *
     * @param string $slug Desired slug
     * @param int $post_id Post ID (for updates)
     * @return string Unique slug
     */
    private function generate_unique_slug($slug, $post_id = 0)
    {
        global $wpdb;

        // Make sure the slug is valid
        $slug = sanitize_title($slug);

        // Check if slug already exists
        $sql = $wpdb->prepare(
            "SELECT post_name FROM $wpdb->posts 
            WHERE post_name = %s 
            AND post_type = 'post' 
            AND ID != %d 
            LIMIT 1",
            $slug,
            $post_id
        );

        $exists = $wpdb->get_var($sql);

        // If slug exists, append a number to make it unique
        if ($exists) {
            $suffix = 2;
            do {
                $alt_slug = $slug . '-' . $suffix;
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_name FROM $wpdb->posts 
                    WHERE post_name = %s 
                    AND post_type = 'post' 
                    AND ID != %d 
                    LIMIT 1",
                    $alt_slug,
                    $post_id
                ));
                $suffix++;
            } while ($exists);
            $slug = $alt_slug;
        }

        return $slug;
    }

    /**
     * Set featured image from URL
     *
     * @param int $post_id Post ID
     * @param string $image_url URL of the image to set as featured
     */
    private function set_featured_image($post_id, $image_url)
    {
        // Check if image already exists for this post
        if (has_post_thumbnail($post_id)) {
            error_log('Post already has a featured image. Skipping.');
            return;
        }

        // Check if the media file already exists in the library
        $existing_id = $this->find_existing_media($image_url);
        if ($existing_id) {
            set_post_thumbnail($post_id, $existing_id);
            error_log('Using existing media file for featured image.');
            return;
        }

        // Download the image
        $image_id = $this->upload_image_from_url($image_url, $post_id);

        if ($image_id && !is_wp_error($image_id)) {
            set_post_thumbnail($post_id, $image_id);
            error_log('Featured image set successfully.');
        } else {
            error_log('Failed to set featured image: ' . ($image_id ? $image_id->get_error_message() : 'Unknown error'));
        }
    }

    /**
     * Upload image from URL and attach to post
     *
     * @param string $image_url URL of the image to upload
     * @param int $post_id Post ID to attach the image to
     * @return int|WP_Error The attachment ID or WP_Error on failure
     */
    private function upload_image_from_url($image_url, $post_id)
    {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Download file to temp location
        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            error_log('Error downloading image: ' . $tmp->get_error_message());
            return $tmp;
        }

        // Get the filename and extension
        $file_array = [
            'name' => basename($image_url),
            'tmp_name' => $tmp
        ];

        // Check image file type
        $filetype = wp_check_filetype($file_array['name']);
        if (!in_array($filetype['type'], ['image/jpeg', 'image/png', 'image/gif'])) {
            unlink($tmp);
            error_log('Invalid image file type: ' . $filetype['type']);
            return new \WP_Error('invalid_image', 'Invalid image file type');
        }

        // Do the validation and storage stuff
        $image_id = media_handle_sideload($file_array, $post_id);

        // If error storing permanently, unlink
        if (is_wp_error($image_id)) {
            @unlink($file_array['tmp_name']);
            error_log('Error handling sideload: ' . $image_id->get_error_message());
            return $image_id;
        }

        // Generate attachment metadata
        $attach_data = wp_generate_attachment_metadata($image_id, get_attached_file($image_id));
        wp_update_attachment_metadata($image_id, $attach_data);

        return $image_id;
    }

    /**
     * Check if media file already exists by URL
     *
     * @param string $image_url URL of the image to check
     * @return int|false Attachment ID if found, false otherwise
     */
    private function find_existing_media($image_url)
    {
        global $wpdb;

        $filename = basename($image_url);
        $query = $wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value LIKE %s",
            '%' . $wpdb->esc_like($filename)
        );

        $attachment_id = $wpdb->get_var($query);

        return $attachment_id ? (int)$attachment_id : false;
    }

    /**
     * Handle term assignments for a post.
     *
     * @param int   $post_id Post ID.
     * @param array $data    Post data array.
     */
    private function handle_terms($post_id, $data)
    {
        if (! empty($data['journalTitle'])) {
            $this->assign_category_terms($post_id, $data['journalTitle'], 'journal');
        }

        if (! empty($data['KeywordList'])) {
            wp_set_post_terms($post_id, $data['KeywordList'], 'keyword');
            // Keywords for the Rankmath
            //update_post_meta($post_id, 'rank_math_focus_keyword', implode(", ", array_unique($data['KeywordList'])));
        }

        if (! empty($data['authors'])) {
            $authors      = wp_list_pluck($data['authors'], 'name');
            $affiliations = array_merge(...wp_list_pluck($data['authors'], 'affiliation'));
            $unique_affiliations = array_unique($affiliations);

            foreach ($unique_affiliations as $affiliation) {
                // Trim the term name to avoid exceeding database limits (200 characters for the name).
                if (strlen($affiliation) > 200) {
                    $affiliation = substr($affiliation, 0, 200);
                }
                $this->assign_category_terms($post_id, $affiliation, 'institution');
            }
            wp_set_post_terms($post_id, $authors, 'author');

            $this->insert_meta_fields($post_id, $data['authors']);
        }
    }

    /**
     * Assign terms to a post with descriptions.
     *
     * @param int    $post_id  Post ID.
     * @param array  $data     Data array.
     * @param string $key      Data key for terms.
     * @param string $taxonomy Taxonomy name.
     */
    private function assign_category_terms($post_id, $term_name, $taxonomy)
    {
        // Check if the term exists
        $term = get_term_by('name', $term_name, $taxonomy);

        if (!$term) {
            // Create the term if it doesn't exist
            $result = wp_insert_term(
                $term_name,
                $taxonomy,
                ['description' => '']
            );

            if (is_wp_error($result)) {
                error_log('Error creating term: ' . $result->get_error_message());
                return;
            }

            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }

        // Assign the term to the post
        wp_set_post_terms($post_id, [$term_id], $taxonomy);
        error_log('Term assigned to post ID ' . $post_id . ': ' . $term_name);
    }

    /**
     * Insert ACF repeater fields.
     */
    private function insert_meta_fields($post_id, $data)
    {
        if (!function_exists('update_field')) {
            error_log('ACF plugin is not active.');
            return;
        }

        $repeater_data = array_map(function ($row) {
            $author = self::get_term_id_by_slug($row['name'], 'author');
            // Extract author term IDs from the authors array
            $affiliation = array_map(function ($term) {
                return self::get_term_id_by_slug($term, 'institution');
            }, $row['affiliation']);

            return compact('author', 'affiliation');
        }, $data);

        update_field('author_affiliation', $repeater_data, $post_id);
    }

    /**
     * Retrieves the term ID by its slug and taxonomy.
     *
     * This function looks up a term by its slug within a specified taxonomy
     * and returns the term ID if found. It handles errors by returning null
     * when the term is not found or if an error occurs.
     *
     * @param string $slug     The slug of the term to find.
     * @param string $taxonomy The taxonomy to search within.
     *
     * @return int|null The term ID if found, null otherwise.
     */
    public static function get_term_id_by_slug($slug, $taxonomy)
    {
        // Attempt to retrieve the term by its slug and taxonomy
        $term = get_term_by('slug', $slug, $taxonomy);

        // Check if the term was found and not an error
        if ($term && !is_wp_error($term)) {
            // Return the term ID
            return $term->term_id;
        }

        // Return null if the term was not found or an error occurred
        return null;
    }
}
