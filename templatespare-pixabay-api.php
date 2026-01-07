<?php

/**
 * Plugin Name: TemplateSpare Pixabay Image API
 * Description: REST API endpoint to fetch images from Pixabay.
 * Version: 1.0.0
 * Author: TemplateSpare
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
  exit;
}

class TemplateSpare_Pixabay_API
{
  private $pixabay_api_key; // 🔑 Replace with your real Pixabay API key

  public function __construct()
  {
    $this->pixabay_api_key = defined('AF_API_TOKEN') ? AF_API_TOKEN : '';
    add_action('rest_api_init', [$this, 'register_routes']);
  }

  /**
   * Register REST API routes
   */
  public function register_routes()
  {
    register_rest_route('templatespare/v1', '/get-images', array(
      'methods' => 'GET',
      'callback' => [$this, 'get_search_images'],
      'permission_callback' => [$this, 'check_permissions'], // Restrict as needed
    ));
  }

  /**
   * Fetch images from Pixabay
   */
  public function get_search_images(WP_REST_Request $request)
  {
    $query = sanitize_text_field($request->get_param('query'));
    $lang = sanitize_text_field($request->get_param('lang'));

    if (empty($query)) {
      return rest_ensure_response([]);
    }

    // Replace commas and spaces with +
    $query = str_replace([',', ' '], '+', $query);

    // Truncate to 100 characters if needed
    if (strlen($query) > 100) {
      $query = substr($query, 0, 100);   // truncate to 100 chars
    }


    $url = add_query_arg([
      'key'        => $this->pixabay_api_key,
      'q'          => urlencode($query),
      'image_type' => 'photo',
      'pretty'     => true,
      'lang' => $lang,
      'orientation' => 'horizontal',
      //'category' => urlencode($query),
      'per_page'   => 20,
    ], 'https://pixabay.com/api/');

    $response = wp_remote_get($url, [
      'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
      error_log('Pixabay Error: ' . $response->get_error_message());
      return rest_ensure_response([]);
    }

    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code !== 200) {
      error_log('Pixabay HTTP Error: ' . $status_code);
      return rest_ensure_response([]);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Only return hits array to match typical frontend usage
    $images = $data['hits'] ?? [];

    return rest_ensure_response($images);
  }

  /**
   * Permissions check
   */
  public function check_permissions()
  {
    // ⚠️ Change to __return_true to make public, or restrict to logged-in users
    return true;
  }
}

new TemplateSpare_Pixabay_API();
