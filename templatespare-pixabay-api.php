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

    // ✅ Convert your language to Pixabay format
    $pixabay_lang = $this->get_pixabay_language($lang);

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

    // ✅ Enhance query based on language context
    $enhanced_query = $this->enhance_query_with_language($query_array, $lang);

    // Ensure max 100 characters
    if (strlen($enhanced_query) > 100) {
      $enhanced_query = substr($enhanced_query, 0, 100);
    }

    // Smart category detection
    $category = $this->detect_category($enhanced_query);

    // Build parameters
    $params = [
      'key'         => $this->pixabay_api_key,
      'q'           => urlencode($enhanced_query),
      'image_type'  => 'photo',
      'lang'        => $pixabay_lang, // ✅ Use converted language
      'orientation' => 'horizontal',
      'order'       => 'popular',
      'per_page'    => 50,
      'safesearch'  => 'true',
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

    // Filter by minimum quality
    $images = $this->filter_quality_images($images, 10);

    // Sort by relevance
    $images = $this->sort_by_relevance($images, $enhanced_query);

    // Return top 20
    return rest_ensure_response(array_slice($images, 0, 20));
  }

  /**
   * ✅ Map your custom language names to Pixabay language codes
   */
  private function get_pixabay_language($lang)
  {
    $language_map = [
      'english'  => 'en',
      'french'   => 'fr',
      'german'   => 'de',
      'nepali'   => 'en', // Not supported, fallback to English
      'rtl'      => 'ar', // RTL → Arabic
      'indian'   => 'en', // Hindi not supported, fallback to English
      'spanish'  => 'es',
      'russian'  => 'ru',
      'japanese' => 'ja',
      'china'    => 'zh', // Chinese
      'turkish'  => 'tr',
    ];

    return $language_map[strtolower($lang)] ?? 'en';
  }

  /**
   * ✅ Enhance query with language-specific context
   */
  private function enhance_query_with_language($query_array, $lang)
  {
    $query_string = implode(' ', $query_array);
    $query_lower = strtolower($query_string);

    // Language-specific enhancements
    $language_keywords = [
      'china'    => ['chinese', 'china', 'asian'],
      'japanese' => ['japanese', 'japan', 'asian'],
      'indian'   => ['indian', 'india', 'asian'],
      'rtl'      => ['arabic', 'middle east', 'arabic'],
      'nepali'   => ['nepali', 'nepal', 'himalayan'],
    ];

    // Check if we need to add language context
    $lang_lower = strtolower($lang);

    // Don't add language keywords if query already contains them
    if (isset($language_keywords[$lang_lower])) {
      $has_language_context = false;
      foreach ($language_keywords[$lang_lower] as $keyword) {
        if (stripos($query_lower, $keyword) !== false) {
          $has_language_context = true;
          break;
        }
      }

      // Add language context if not present
      if (!$has_language_context && in_array($lang_lower, ['china', 'japanese', 'indian', 'nepali'])) {
        $query_string .= ' ' . $language_keywords[$lang_lower][0];
      }
    }

    // General query enhancements
    $enhancements = [
      'news media' => 'news media journalism',
      'local news' => 'local news reporter',
      'magazine' => 'magazine publication',
      'newspaper' => 'newspaper press',
    ];

    foreach ($enhancements as $key => $value) {
      if (stripos($query_lower, $key) !== false && $query_string === $query_lower) {
        $query_string = $value;
        break;
      }
    }

    return trim($query_string);
  }

  /**
   * ✅ Smart category detection
   */
  private function detect_category($query)
  {
    $query_lower = strtolower($query);

    $category_keywords = [
      'people' => ['portrait', 'person', 'man', 'woman', 'people', 'reporter', 'journalist'],
      'business' => ['news', 'media', 'office', 'meeting', 'professional', 'magazine', 'newspaper'],
      'places' => ['china', 'japan', 'india', 'nepal', 'location', 'regional', 'local'],
      'religion' => ['temple', 'religious', 'spiritual', 'shrine', 'monastery'],
      'buildings' => ['architecture', 'building', 'city', 'urban'],
      'nature' => ['nature', 'landscape', 'mountain', 'himalayan'],
      'food' => ['food', 'cuisine', 'dish', 'cooking'],
      'travel' => ['travel', 'tourism', 'destination'],
      'music' => ['music', 'concert', 'instrument'],
    ];

    foreach ($category_keywords as $category => $keywords) {
      foreach ($keywords as $keyword) {
        if (stripos($query_lower, $keyword) !== false) {
          return $category;
        }
      }
    }

    return '';
  }

  /**
   * ✅ Filter low-quality images
   */
  private function filter_quality_images($images, $min_likes = 10)
  {
    return array_filter($images, function ($image) use ($min_likes) {
      return isset($image['likes']) && $image['likes'] >= $min_likes;
    });
  }

  /**
   * ✅ Sort by relevance
   */
  private function sort_by_relevance($images, $query)
  {
    $query_terms = array_map('strtolower', preg_split('/\s+/', $query));

    foreach ($images as &$image) {
      $relevance_score = 0;
      $tags = strtolower($image['tags'] ?? '');

      foreach ($query_terms as $term) {
        if (empty($term)) continue;

        if (strpos($tags, $term) !== false) {
          $relevance_score += 20;
        }

        if (stripos($tags, substr($term, 0, 4)) !== false) {
          $relevance_score += 5;
        }
      }

      $relevance_score += min(($image['likes'] ?? 0) / 50, 10);
      $relevance_score += min(($image['downloads'] ?? 0) / 1000, 10);

      if (($image['imageWidth'] ?? 0) >= 1920) {
        $relevance_score += 5;
      }

      $image['_relevance'] = $relevance_score;
    }

    usort($images, function ($a, $b) {
      return ($b['_relevance'] ?? 0) <=> ($a['_relevance'] ?? 0);
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
