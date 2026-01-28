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

    // Words to exclude (builder/framework names, etc.)
    $exclude_words = ['free', 'pro', 'child', 'elementor', 'wordpress', 'wp'];

    // Split query by spaces (dynamic input)
    $query_array = preg_split('/\s+/', $query);
    $query_array = array_map('trim', $query_array);
    $query_array = array_filter($query_array); // Remove empty

    // Remove excluded words
    $query_array = array_filter($query_array, function ($item) use ($exclude_words) {
      $item_lower = strtolower($item);
      foreach ($exclude_words as $word) {
        if ($item_lower === $word || stripos($item, $word) !== false) {
          return false;
        }
      }
      return strlen($item) > 1; // Remove single chars
    });

    // Reset array keys
    $query_array = array_values($query_array);

    if (empty($query_array)) {
      return rest_ensure_response([]);
    }

    // ✅ Detect category from all keywords
    $detected_category = $this->detect_category_from_keywords($query_array);

    // ✅ Optimize and enhance query
    $optimized_query = $this->optimize_query_keywords($query_array, $detected_category);

    // ✅ Add language/regional context
    $optimized_query = $this->add_regional_context($optimized_query, $query_array, $lang);

    // Ensure max 100 characters
    if (strlen($optimized_query) > 100) {
      $optimized_query = substr($optimized_query, 0, 100);
    }

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
   * ✅ Detect category from ANY keyword combination
   */
  private function detect_category_from_keywords($keywords_array)
  {
    $category_mapping = [
      // HIGH PRIORITY (Specific content types) - Priority 5
      'medical' => ['pixabay_category' => 'health', 'priority' => 5],
      'covid' => ['pixabay_category' => 'health', 'priority' => 5],
      'pandemic' => ['pixabay_category' => 'health', 'priority' => 5],
      'dental' => ['pixabay_category' => 'health', 'priority' => 5],
      'healthcare' => ['pixabay_category' => 'health', 'priority' => 5],

      'pet' => ['pixabay_category' => 'animals', 'priority' => 5],
      'dog' => ['pixabay_category' => 'animals', 'priority' => 5],
      'cat' => ['pixabay_category' => 'animals', 'priority' => 5],

      'food' => ['pixabay_category' => 'food', 'priority' => 5],
      'restaurant' => ['pixabay_category' => 'food', 'priority' => 4],
      'cuisine' => ['pixabay_category' => 'food', 'priority' => 4],

      'gadget' => ['pixabay_category' => 'computer', 'priority' => 5],
      'gadgets' => ['pixabay_category' => 'computer', 'priority' => 5],
      'technology' => ['pixabay_category' => 'computer', 'priority' => 4],
      'tech' => ['pixabay_category' => 'computer', 'priority' => 4],

      'lawyer' => ['pixabay_category' => 'business', 'priority' => 5],
      'attorney' => ['pixabay_category' => 'business', 'priority' => 5],
      'legal' => ['pixabay_category' => 'business', 'priority' => 4],

      'education' => ['pixabay_category' => 'education', 'priority' => 5],
      'school' => ['pixabay_category' => 'education', 'priority' => 4],
      'student' => ['pixabay_category' => 'education', 'priority' => 4],

      'industry' => ['pixabay_category' => 'industry', 'priority' => 5],
      'factory' => ['pixabay_category' => 'industry', 'priority' => 4],
      'manufacturing' => ['pixabay_category' => 'industry', 'priority' => 4],

      'portfolio' => ['pixabay_category' => 'business', 'priority' => 4],

      // MEDIUM PRIORITY (Multi-word phrases handled separately) - Priority 4
      'real' => ['pixabay_category' => 'buildings', 'priority' => 4],
      'estate' => ['pixabay_category' => 'buildings', 'priority' => 4],
      'property' => ['pixabay_category' => 'buildings', 'priority' => 4],
      'house' => ['pixabay_category' => 'buildings', 'priority' => 3],

      'doctor' => ['pixabay_category' => 'health', 'priority' => 4],
      'hospital' => ['pixabay_category' => 'health', 'priority' => 4],
      'nurse' => ['pixabay_category' => 'health', 'priority' => 4],
      'clinic' => ['pixabay_category' => 'health', 'priority' => 4],

      // REGIONAL/GEOGRAPHIC (Special handling) - Priority 3
      'regional' => ['pixabay_category' => 'places', 'priority' => 3],
      'local' => ['pixabay_category' => 'places', 'priority' => 3],
      'indian' => ['pixabay_category' => 'places', 'priority' => 3],
      'chinese' => ['pixabay_category' => 'places', 'priority' => 3],
      'japanese' => ['pixabay_category' => 'places', 'priority' => 3],
      'nepali' => ['pixabay_category' => 'places', 'priority' => 3],
      'china' => ['pixabay_category' => 'places', 'priority' => 3],
      'japan' => ['pixabay_category' => 'places', 'priority' => 3],
      'india' => ['pixabay_category' => 'places', 'priority' => 3],
      'nepal' => ['pixabay_category' => 'places', 'priority' => 3],

      // LOW PRIORITY (Generic descriptors) - Priority 2
      'news' => ['pixabay_category' => 'business', 'priority' => 2],
      'media' => ['pixabay_category' => 'business', 'priority' => 2],
      'magazine' => ['pixabay_category' => 'business', 'priority' => 2],
      'business' => ['pixabay_category' => 'business', 'priority' => 2],
      'lifestyle' => ['pixabay_category' => 'people', 'priority' => 2],
      'personal' => ['pixabay_category' => 'people', 'priority' => 2],
      'design' => ['pixabay_category' => 'business', 'priority' => 2],
      'creative' => ['pixabay_category' => 'business', 'priority' => 2],
    ];

    $best_match = null;
    $highest_priority = 0;

    foreach ($keywords_array as $keyword) {
      $keyword_lower = strtolower(trim($keyword));

      if (isset($category_mapping[$keyword_lower])) {
        $match = $category_mapping[$keyword_lower];

        // Keep highest priority category
        if ($match['priority'] > $highest_priority) {
          $highest_priority = $match['priority'];
          $best_match = $match;
        }
      }
    }

    return $best_match ?? ['pixabay_category' => '', 'priority' => 0];
  }

  /**
   * ✅ Optimize keywords based on priority and relevance
   */
  private function optimize_query_keywords($keywords_array, $detected_category)
  {
    $keywords_lower = array_map('strtolower', array_map('trim', $keywords_array));

    // TIER 1: Specific content types (KEEP ALWAYS)
    $tier1_keywords = [
      'medical',
      'covid',
      'pandemic',
      'dental',
      'healthcare',
      'food',
      'cuisine',
      'restaurant',
      'gadget',
      'gadgets',
      'technology',
      'tech',
      'lawyer',
      'attorney',
      'legal',
      'pet',
      'dog',
      'cat',
      'animal',
      'education',
      'school',
      'student',
      'industry',
      'factory',
      'manufacturing',
      'portfolio',
      'real',
      'estate',
      'property'
    ];

    // TIER 2: Descriptive/context (KEEP 2-3)
    $tier2_keywords = [
      'news',
      'media',
      'magazine',
      'business',
      'lifestyle',
      'personal',
      'design',
      'creative',
      'professional',
      'corporate',
      'office'
    ];

    // TIER 3: Geographic/Regional (KEEP 1-2)
    $tier3_keywords = [
      'regional',
      'local',
      'indian',
      'chinese',
      'japanese',
      'nepali',
      'china',
      'japan',
      'india',
      'nepal'
    ];

    $selected = [
      'tier1' => [],
      'tier2' => [],
      'tier3' => []
    ];

    // Collect keywords by tier
    foreach ($keywords_lower as $keyword) {
      if (in_array($keyword, $tier1_keywords)) {
        $selected['tier1'][] = $keyword;
      } elseif (in_array($keyword, $tier2_keywords)) {
        $selected['tier2'][] = $keyword;
      } elseif (in_array($keyword, $tier3_keywords)) {
        $selected['tier3'][] = $keyword;
      }
    }

    // Build final query
    $final_keywords = [];

    // Add ALL tier1 (most important)
    $final_keywords = array_merge($final_keywords, $selected['tier1']);

    // Add tier3 (geographic context) - limit to 2
    $final_keywords = array_merge($final_keywords, array_slice($selected['tier3'], 0, 2));

    // Add tier2 (descriptive) - fill up to 5 total keywords
    $remaining_slots = max(0, 5 - count($final_keywords));
    if ($remaining_slots > 0) {
      $final_keywords = array_merge($final_keywords, array_slice($selected['tier2'], 0, $remaining_slots));
    }

    // If we still have very few keywords, add others
    if (count($final_keywords) < 2) {
      foreach ($keywords_lower as $keyword) {
        if (!in_array($keyword, $final_keywords) && strlen($keyword) > 2) {
          $final_keywords[] = $keyword;
          if (count($final_keywords) >= 3) break;
        }
      }
    }

    $query_string = implode(' ', array_unique($final_keywords));

    // Add category-specific context
    if (!empty($detected_category['pixabay_category'])) {
      $query_string = $this->add_category_context($query_string, $detected_category);
    }

    return trim($query_string);
  }

  /**
   * ✅ Add regional/language context
   */
  private function add_regional_context($query, $original_keywords, $lang)
  {
    $query_lower = strtolower($query);
    $keywords_lower = array_map('strtolower', $original_keywords);

    // Check if regional keywords present
    $regional_keywords = ['regional', 'local', 'indian', 'chinese', 'japanese', 'nepali'];
    $has_regional = false;

    foreach ($regional_keywords as $regional) {
      if (in_array($regional, $keywords_lower)) {
        $has_regional = true;
        break;
      }
    }

    // Language-specific additions
    $language_map = [
      'indian' => 'india',
      'china' => 'chinese',
      'chinese' => 'china',
      'japanese' => 'japan',
      'nepali' => 'nepal',
    ];

    // Add language context if present in keywords or lang param
    $lang_lower = strtolower($lang);

    if (isset($language_map[$lang_lower]) && stripos($query_lower, $language_map[$lang_lower]) === false) {
      $query .= ' ' . $language_map[$lang_lower];
    }

    // Check if keywords themselves have geographic info
    foreach ($language_map as $keyword => $addition) {
      if (in_array($keyword, $keywords_lower) && stripos($query_lower, $addition) === false) {
        $query .= ' ' . $addition;
        break; // Only add one
      }
    }

    return trim($query);
  }

  /**
   * ✅ Add category-specific enhancement
   */
  private function add_category_context($query, $detected_category)
  {
    $query_lower = strtolower($query);

    $category_enhancements = [
      'food' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'culinary'
      ],
      'health' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'wellness'
      ],
      'animals' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'care'
      ],
      'computer' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'electronics'
      ],
      'buildings' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'architecture'
      ],
      'education' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'learning'
      ],
      'industry' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'production'
      ],
      'business' => [
        'triggers' => ['news', 'magazine', 'media'],
        'add' => 'professional'
      ],
    ];

    $pixabay_cat = $detected_category['pixabay_category'];

    if (isset($category_enhancements[$pixabay_cat])) {
      $enhancement = $category_enhancements[$pixabay_cat];

      // Check if query contains trigger words
      foreach ($enhancement['triggers'] as $trigger) {
        if (stripos($query_lower, $trigger) !== false) {
          // Add enhancement if not already present and space allows
          if (stripos($query_lower, $enhancement['add']) === false && strlen($query) < 80) {
            $query .= ' ' . $enhancement['add'];
          }
          break;
        }
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
        if (empty($term) || strlen($term) < 2) continue;

        // Exact word match
        if (preg_match('/\b' . preg_quote($term, '/') . '\b/', $tags)) {
          $relevance_score += 25;
        }
        // Partial match
        elseif (strpos($tags, $term) !== false) {
          $relevance_score += 10;
        }
        // Substring match (min 4 chars)
        elseif (strlen($term) >= 4 && stripos($tags, substr($term, 0, 4)) !== false) {
          $relevance_score += 3;
        }
      }

      // Popularity boost (secondary factor)
      $relevance_score += min(($image['likes'] ?? 0) / 100, 8);
      $relevance_score += min(($image['downloads'] ?? 0) / 2000, 8);

      // Quality boost
      if (($image['imageWidth'] ?? 0) >= 1920) {
        $relevance_score += 4;
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
