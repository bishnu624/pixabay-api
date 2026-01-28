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
    add_shortcode('latest_world_news', [$this, 'lwn_display_latest_news']);
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
    $lang  = sanitize_text_field($request->get_param('lang'));

    // Words to exclude
    $exclude_words = ['free', 'pro', 'child'];

    // Split query into array and trim spaces
    $query_array = array_map('trim', explode(',', $query));

    // Remove excluded words (case-insensitive)
    $query_array = array_filter($query_array, function ($item) use ($exclude_words) {
      foreach ($exclude_words as $word) {
        if (stripos($item, $word) !== false) {
          return false;
        }
      }
      return true;
    });

    // ✅ NEW: Map news-related terms to better keywords
    $keyword_mapping = [
      'news media' => 'journalism newspaper',
      'local news' => 'news reporter',
      'regional news' => 'news broadcasting',
      'media' => 'journalism',
      'news' => 'newspaper journalist',
    ];

    // Apply keyword mapping for better results
    $query_array = array_map(function ($term) use ($keyword_mapping) {
      $term_lower = strtolower(trim($term));
      return $keyword_mapping[$term_lower] ?? $term;
    }, $query_array);

    // Rebuild query string
    $query = implode(' ', $query_array); // ✅ Changed from comma to space

    // Ensure max 100 characters
    if (strlen($query) > 100) {
      $query = substr($query, 0, 100);
    }

    // Compare with allowed Pixabay categories
    $compare_cat = [
      "backgrounds",
      "fashion",
      "nature",
      "science",
      "education",
      "feelings",
      "health",
      "people",
      "religion",
      "places",
      "animals",
      "industry",
      "computer",
      "food",
      "sports",
      "transportation",
      "travel",
      "buildings",
      "business", // ✅ Best for news/media
      "music"
    ];

    // ✅ NEW: Smart category detection for news-related queries
    $category = '';
    $query_lower = strtolower($query);

    if (preg_match('/\b(news|media|journalist|newspaper|broadcasting|reporter)\b/i', $query_lower)) {
      $category = 'business'; // Best category for news images
    } else {
      // Original category matching logic
      $query_array_lower = array_map('strtolower', explode(' ', $query));
      $compare_cat_lower = array_map('strtolower', $compare_cat);
      $common_categories = array_values(array_intersect($query_array_lower, $compare_cat_lower));
      $category = !empty($common_categories) ? $common_categories[0] : '';
    }

    // ✅ NEW: Add order parameter for better relevance
    $params = [
      'key'         => $this->pixabay_api_key,
      'q'           => urlencode($query),
      'image_type'  => 'photo',
      'lang'        => $lang ?: 'en',
      'orientation' => 'horizontal',
      'order'       => 'popular', // ✅ Most relevant results first
      'per_page'    => 50, // ✅ Get more results to filter from
    ];

    if (!empty($category)) {
      $params['category'] = $category;
    }

    $url = add_query_arg($params, 'https://pixabay.com/api/');

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

    $images = $data['hits'] ?? [];

    // ✅ NEW: Filter and sort by relevance
    $images = $this->filter_relevant_images($images, $query);

    // Return top 20 most relevant
    return rest_ensure_response(array_slice($images, 0, 20));
  }

  /**
   * ✅ NEW: Filter images by relevance score
   */
  private function filter_relevant_images($images, $query)
  {
    $query_terms = array_map('strtolower', explode(' ', $query));

    foreach ($images as &$image) {
      $relevance_score = 0;

      // Check tag matches
      $tags = strtolower($image['tags']);
      foreach ($query_terms as $term) {
        if (stripos($tags, $term) !== false) {
          $relevance_score += 10;
        }
      }

      // Boost by popularity
      $relevance_score += ($image['likes'] / 100);
      $relevance_score += ($image['downloads'] / 1000);

      // Boost high-quality images
      if (!empty($image['imageWidth']) && $image['imageWidth'] >= 1920) {
        $relevance_score += 5;
      }

      $image['relevance_score'] = $relevance_score;
    }

    // Sort by relevance score
    usort($images, function ($a, $b) {
      return $b['relevance_score'] <=> $a['relevance_score'];
    });

    return $images;
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
