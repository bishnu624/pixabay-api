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

  public function get_search_images(WP_REST_Request $request)
  {
    $query = sanitize_text_field($request->get_param('query'));
    $lang  = sanitize_text_field($request->get_param('lang'));

    // Convert language
    $pixabay_lang = $this->get_pixabay_language($lang);

    // Words to exclude
    $exclude_words = ['free', 'pro', 'child', 'elementor']; // ✅ Added elementor to exclude

    // Split query into array and trim spaces
    $query_array = array_map('trim', explode(' ', $query)); // ✅ Changed from ',' to ' '

    // Remove excluded words
    $query_array = array_filter($query_array, function ($item) use ($exclude_words) {
      foreach ($exclude_words as $word) {
        if (stripos($item, $word) !== false) {
          return false;
        }
      }
      return true;
    });

    // ✅ NEW: Detect category from keywords in the query
    $detected_category = $this->detect_category_from_keywords($query_array);

    // ✅ NEW: Smart keyword selection and enhancement
    $optimized_query = $this->optimize_query_keywords($query_array, $detected_category);

    // Ensure max 100 characters
    if (strlen($optimized_query) > 100) {
      $optimized_query = substr($optimized_query, 0, 100);
    }

    // Add language context if needed
    $optimized_query = $this->enhance_query_with_language_context($optimized_query, $lang);

    // Build parameters
    $params = [
      'key'         => $this->pixabay_api_key,
      'q'           => urlencode($optimized_query),
      'image_type'  => 'photo',
      'lang'        => $pixabay_lang,
      'orientation' => 'horizontal',
      'order'       => 'popular',
      'per_page'    => 50,
      'safesearch'  => 'true',
    ];

    // Add category if detected
    if (!empty($detected_category['pixabay_category'])) {
      $params['category'] = $detected_category['pixabay_category'];
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
    $images = $this->sort_by_relevance($images, $optimized_query);

    // Return top 20
    return rest_ensure_response(array_slice($images, 0, 20));
  }

  /**
   * ✅ NEW: Detect category from keywords in query
   */
  private function detect_category_from_keywords($keywords_array)
  {
    $category_mapping = [
      // Content type categories
      'pet' => ['pixabay_category' => 'animals', 'priority' => 5],
      'animal' => ['pixabay_category' => 'animals', 'priority' => 5],
      'dog' => ['pixabay_category' => 'animals', 'priority' => 5],
      'cat' => ['pixabay_category' => 'animals', 'priority' => 5],

      'food' => ['pixabay_category' => 'food', 'priority' => 5],
      'cuisine' => ['pixabay_category' => 'food', 'priority' => 4],
      'restaurant' => ['pixabay_category' => 'food', 'priority' => 4],
      'cooking' => ['pixabay_category' => 'food', 'priority' => 4],

      'covid' => ['pixabay_category' => 'health', 'priority' => 5],
      'pandemic' => ['pixabay_category' => 'health', 'priority' => 5],
      'health' => ['pixabay_category' => 'health', 'priority' => 4],
      'medical' => ['pixabay_category' => 'health', 'priority' => 5], // ✅ ADD THIS
      'healthcare' => ['pixabay_category' => 'health', 'priority' => 4], // ✅ ADD THIS
      'dental' => ['pixabay_category' => 'health', 'priority' => 5],
      'doctor' => ['pixabay_category' => 'health', 'priority' => 3],
      'hospital' => ['pixabay_category' => 'health', 'priority' => 4], // ✅ ADD THIS
      'nurse' => ['pixabay_category' => 'health', 'priority' => 4], // ✅ ADD THIS
      'clinic' => ['pixabay_category' => 'health', 'priority' => 4], // ✅ ADD THIS

      'real' => ['pixabay_category' => 'buildings', 'priority' => 3],
      'estate' => ['pixabay_category' => 'buildings', 'priority' => 3],
      'property' => ['pixabay_category' => 'buildings', 'priority' => 4],
      'house' => ['pixabay_category' => 'buildings', 'priority' => 4],
      'building' => ['pixabay_category' => 'buildings', 'priority' => 3],

      'gadget' => ['pixabay_category' => 'computer', 'priority' => 5],
      'gadgets' => ['pixabay_category' => 'computer', 'priority' => 5],
      'technology' => ['pixabay_category' => 'computer', 'priority' => 4],
      'tech' => ['pixabay_category' => 'computer', 'priority' => 4],
      'device' => ['pixabay_category' => 'computer', 'priority' => 3],

      'lawyer' => ['pixabay_category' => 'business', 'priority' => 5],
      'legal' => ['pixabay_category' => 'business', 'priority' => 4],
      'attorney' => ['pixabay_category' => 'business', 'priority' => 5],

      'education' => ['pixabay_category' => 'education', 'priority' => 5],
      'school' => ['pixabay_category' => 'education', 'priority' => 4],
      'student' => ['pixabay_category' => 'education', 'priority' => 4],
      'teacher' => ['pixabay_category' => 'education', 'priority' => 4],

      'industry' => ['pixabay_category' => 'industry', 'priority' => 5],
      'factory' => ['pixabay_category' => 'industry', 'priority' => 4],
      'manufacturing' => ['pixabay_category' => 'industry', 'priority' => 4],

      // Generic categories (lower priority)
      'business' => ['pixabay_category' => 'business', 'priority' => 3],
      'office' => ['pixabay_category' => 'business', 'priority' => 2],
      'news' => ['pixabay_category' => 'business', 'priority' => 2],
      'media' => ['pixabay_category' => 'business', 'priority' => 2],
      'magazine' => ['pixabay_category' => 'business', 'priority' => 2],

      'lifestyle' => ['pixabay_category' => 'people', 'priority' => 3],
      'personal' => ['pixabay_category' => 'people', 'priority' => 2],
      'people' => ['pixabay_category' => 'people', 'priority' => 2],
      'portrait' => ['pixabay_category' => 'people', 'priority' => 3],

      'design' => ['pixabay_category' => 'business', 'priority' => 2],
      'creative' => ['pixabay_category' => 'business', 'priority' => 2],
      'portfolio' => ['pixabay_category' => 'business', 'priority' => 3],
    ];

    $best_match = null;
    $highest_priority = 0;

    foreach ($keywords_array as $keyword) {
      $keyword_lower = strtolower(trim($keyword));

      if (isset($category_mapping[$keyword_lower])) {
        $match = $category_mapping[$keyword_lower];

        if ($match['priority'] > $highest_priority) {
          $highest_priority = $match['priority'];
          $best_match = $match;
        }
      }
    }

    return $best_match ?? ['pixabay_category' => '', 'priority' => 0];
  }

  /**
   * ✅ NEW: Optimize and combine keywords intelligently
   */
  private function optimize_query_keywords($keywords_array, $detected_category)
  {
    $keywords_lower = array_map('strtolower', array_map('trim', $keywords_array));

    // Priority keywords (most specific/important)
    $priority_keywords = [
      'food',
      'covid',
      'pandemic',
      'dental',
      'gadgets',
      'gadget',
      'lawyer',
      'attorney',
      'pet',
      'real',
      'estate',
      'portfolio',
      'education',
      'industry',
      'health',
      'medical',
      'healthcare',
      'hospital',
      'doctor',
      'nurse',
      'clinic' // ✅ ADD THESE
    ];

    // Secondary keywords (descriptive)
    $secondary_keywords = [
      'news',
      'media',
      'magazine',
      'lifestyle',
      'personal',
      'design',
      'creative',
      'business'
    ];

    // Generic keywords (usually not needed)
    $generic_keywords = [
      'the',
      'and',
      'or',
      'a',
      'an',
      'of',
      'in',
      'to',
      'for'
    ];

    $selected_keywords = [];

    // Step 1: Add all priority keywords
    foreach ($keywords_lower as $keyword) {
      if (in_array($keyword, $priority_keywords)) {
        $selected_keywords[] = $keyword;
      }
    }

    // Step 2: Add up to 2 secondary keywords if we have less than 3 keywords
    if (count($selected_keywords) < 3) {
      foreach ($keywords_lower as $keyword) {
        if (in_array($keyword, $secondary_keywords) && !in_array($keyword, $selected_keywords)) {
          $selected_keywords[] = $keyword;
          if (count($selected_keywords) >= 3) break;
        }
      }
    }

    // Step 3: If still low on keywords, add other non-generic ones
    if (count($selected_keywords) < 2) {
      foreach ($keywords_lower as $keyword) {
        if (
          !in_array($keyword, $generic_keywords) &&
          !in_array($keyword, $selected_keywords) &&
          strlen($keyword) > 2
        ) {
          $selected_keywords[] = $keyword;
          if (count($selected_keywords) >= 3) break;
        }
      }
    }

    // Enhance based on detected category
    $query_string = implode(' ', $selected_keywords);

    if (!empty($detected_category['pixabay_category'])) {
      $query_string = $this->add_category_context($query_string, $detected_category);
    }

    return trim($query_string);
  }

  /**
   * ✅ Add specific context based on detected category
   */
  private function add_category_context($query, $detected_category)
  {
    $query_lower = strtolower($query);

    $category_enhancements = [
      'food' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'cuisine culinary'
      ],
      'health' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'medical healthcare'
      ],
      'animals' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'animal care'
      ],
      'computer' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'technology electronics'
      ],
      'buildings' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'property architecture'
      ],
      'education' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'learning academic'
      ],
      'industry' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'manufacturing production'
      ],
    ];

    $pixabay_cat = $detected_category['pixabay_category'];

    if (isset($category_enhancements[$pixabay_cat])) {
      $enhancement = $category_enhancements[$pixabay_cat];

      // Check if query contains trigger words
      foreach ($enhancement['triggers'] as $trigger) {
        if (stripos($query_lower, $trigger) !== false) {
          // Add enhancement if not already present
          $words_to_add = explode(' ', $enhancement['add']);
          foreach ($words_to_add as $word) {
            if (stripos($query_lower, $word) === false) {
              $query .= ' ' . $word;
            }
          }
          break;
        }
      }
    }

    return trim($query);
  }

  /**
   * ✅ Language context enhancement
   */
  private function enhance_query_with_language_context($query, $lang)
  {
    $query_lower = strtolower($query);
    $lang_lower = strtolower($lang);

    $language_keywords = [
      'china'    => ['chinese', 'china'],
      'japanese' => ['japanese', 'japan'],
      'indian'   => ['indian', 'india'],
      'nepali'   => ['nepali', 'nepal'],
      'spanish'  => ['spanish', 'spain'],
      'french'   => ['french', 'france'],
      'german'   => ['german', 'germany'],
      'russian'  => ['russian', 'russia'],
      'turkish'  => ['turkish', 'turkey'],
    ];

    if (isset($language_keywords[$lang_lower])) {
      $has_language = false;
      foreach ($language_keywords[$lang_lower] as $keyword) {
        if (stripos($query_lower, $keyword) !== false) {
          $has_language = true;
          break;
        }
      }

      // Only add language context if query length allows
      if (!$has_language && strlen($query) < 80) {
        $query .= ' ' . $language_keywords[$lang_lower][0];
      }
    }

    return trim($query);
  }

  /**
   * ✅ Language mapping
   */
  private function get_pixabay_language($lang)
  {
    $language_map = [
      'english'  => 'en',
      'french'   => 'fr',
      'german'   => 'de',
      'nepali'   => 'en',
      'rtl'      => 'ar',
      'indian'   => 'en',
      'spanish'  => 'es',
      'russian'  => 'ru',
      'japanese' => 'ja',
      'china'    => 'zh',
      'turkish'  => 'tr',
    ];

    return $language_map[strtolower($lang)] ?? 'en';
  }

  /**
   * ✅ Filter quality
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

        // Exact match
        if (strpos($tags, $term) !== false) {
          $relevance_score += 20;
        }

        // Partial match
        if (strlen($term) > 3 && stripos($tags, substr($term, 0, 4)) !== false) {
          $relevance_score += 5;
        }
      }

      // Popularity boost
      $relevance_score += min(($image['likes'] ?? 0) / 50, 10);
      $relevance_score += min(($image['downloads'] ?? 0) / 1000, 10);

      // Quality boost
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
