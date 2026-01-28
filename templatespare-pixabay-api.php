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
    $per_page = absint($request->get_param('per_page')) ?: 20;

    // Process the query with comprehensive tag mapping
    $processed = $this->process_search_query_comprehensive($query);

    // Build Pixabay API URL
    $url = add_query_arg([
      'key'           => $this->pixabay_api_key,
      'q'             => urlencode($processed['search_query']),
      'image_type'    => 'photo',
      'lang'          => $lang ?: 'en',
      'orientation'   => $processed['orientation'] ?? 'horizontal',
      'category'      => $processed['category'],
      'per_page'      => $per_page,
      'safesearch'    => 'true',
      'order'         => 'popular',
      'min_width'     => 800,
      'min_height'    => 600,
      'colors'        => $processed['colors'] ?? '',
    ], 'https://pixabay.com/api/');

    // Debug logging
    error_log('=== Pixabay Search Debug ===');
    error_log('Original Query: ' . $query);
    error_log('Processed Query: ' . $processed['search_query']);
    error_log('Category: ' . $processed['category']);
    error_log('URL: ' . $url);

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

    error_log('Results Found: ' . count($images));
    error_log('=== End Debug ===');

    return rest_ensure_response($images);
  }

  /**
   * Comprehensive tag processing with ALL JSON tags
   */
  private function process_search_query_comprehensive($query)
  {
    // ALL tags from your JSON with their mappings
    $all_tag_mappings = [
      // From covernews-pro tags
      'pro' => [
        'search_terms' => ['professional', 'premium', 'business', 'corporate'],
        'category' => 'business',
        'colors' => 'grayscale'
      ],
      'magazine' => [
        'search_terms' => ['magazine cover', 'publication', 'layout', 'design'],
        'category' => 'backgrounds',
        'orientation' => 'vertical'
      ],
      'general' => [
        'search_terms' => ['general', 'universal', 'diverse', 'variety'],
        'category' => 'backgrounds'
      ],

      // From covernews tags
      'free' => [
        'search_terms' => ['free', 'accessible', 'open', 'available'],
        'category' => '',
        'exclude' => true  // We'll exclude this from final query
      ],

      // Sport demo tags
      'sport' => [
        'search_terms' => ['sports', 'athlete', 'stadium', 'competition', 'fitness'],
        'category' => 'sports',
        'colors' => 'sporty'  // Vibrant colors
      ],

      // Fashion demo tags
      'fashion' => [
        'search_terms' => ['fashion', 'clothing', 'style', 'model', 'runway', 'trend'],
        'category' => 'fashion',
        'orientation' => 'vertical'
      ],

      // Theme names (from your JSON)
      'covernews' => [
        'search_terms' => ['news', 'headlines', 'newspaper', 'media', 'journalism'],
        'category' => 'backgrounds'
      ],
      'covernews-pro' => [
        'search_terms' => ['premium news', 'professional media', 'corporate journalism'],
        'category' => 'business'
      ],

      // Child themes (from covernews child array)
      'hybridnews' => [
        'search_terms' => ['hybrid', 'mixed media', 'digital news', 'technology news'],
        'category' => 'computer'
      ],
      'newsment' => [
        'search_terms' => ['current events', 'commentary', 'opinion', 'analysis'],
        'category' => 'people'
      ],
      'newscover' => [
        'search_terms' => ['news cover', 'front page', 'headline', 'breaking news'],
        'category' => 'backgrounds',
        'orientation' => 'vertical'
      ],
      'coverstory' => [
        'search_terms' => ['feature story', 'cover story', 'main article', 'lead story'],
        'category' => 'backgrounds'
      ],
      'hardnews' => [
        'search_terms' => ['serious news', 'investigative', 'hard facts', 'reporting'],
        'category' => 'backgrounds',
        'colors' => 'grayscale'
      ],
      'newsquare' => [
        'search_terms' => ['square format', 'social media', 'instagram', 'grid layout'],
        'category' => 'backgrounds',
        'orientation' => 'all'  // Square images
      ],
      'newswords' => [
        'search_terms' => ['text', 'typography', 'words', 'letters', 'editorial'],
        'category' => 'backgrounds'
      ],
      'daily-newscast' => [
        'search_terms' => ['daily news', 'broadcast', 'television', 'anchor', 'studio'],
        'category' => 'backgrounds'
      ],
      'newsport' => [
        'search_terms' => ['sports news', 'athletic news', 'game coverage', 'sports media'],
        'category' => 'sports'
      ],
      'covermag' => [
        'search_terms' => ['magazine', 'cover magazine', 'periodical', 'publication'],
        'category' => 'backgrounds',
        'orientation' => 'vertical'
      ],
      'elenews' => [
        'search_terms' => ['elegant news', 'sophisticated', 'luxury', 'premium media'],
        'category' => 'backgrounds',
        'colors' => 'grayscale,black'
      ],

      // Category mappings from JSON
      'news' => [
        'search_terms' => ['news', 'newspaper', 'headlines', 'media'],
        'category' => 'backgrounds'
      ],
      'media' => [
        'search_terms' => ['media', 'broadcasting', 'television', 'radio'],
        'category' => 'backgrounds'
      ],

      // Additional generic mappings
      'technology' => [
        'search_terms' => ['technology', 'computer', 'digital', 'innovation'],
        'category' => 'computer'
      ],
      'business' => [
        'search_terms' => ['business', 'office', 'corporate', 'meeting'],
        'category' => 'business'
      ],
      'travel' => [
        'search_terms' => ['travel', 'landscape', 'adventure', 'exploration'],
        'category' => 'travel'
      ],
      'nature' => [
        'search_terms' => ['nature', 'landscape', 'environment', 'outdoors'],
        'category' => 'nature'
      ],
      'food' => [
        'search_terms' => ['food', 'cuisine', 'restaurant', 'cooking'],
        'category' => 'food'
      ],
      'health' => [
        'search_terms' => ['health', 'fitness', 'wellness', 'medical'],
        'category' => 'health'
      ],
      'music' => [
        'search_terms' => ['music', 'concert', 'instrument', 'performance'],
        'category' => 'music'
      ],
      'education' => [
        'search_terms' => ['education', 'school', 'learning', 'books'],
        'category' => 'education'
      ],
      'people' => [
        'search_terms' => ['people', 'portrait', 'crowd', 'community'],
        'category' => 'people'
      ],
    ];

    // Words to exclude from final search query
    $exclude_from_query = ['free', 'pro', 'general', 'child'];

    // Split and process query
    $query_array = array_map('trim', explode(',', $query));
    $query_lower = array_map('strtolower', $query_array);

    // Collect search terms and categories
    $search_terms = [];
    $categories = [];
    $orientation = 'horizontal';
    $colors = '';

    foreach ($query_lower as $tag) {
      if (isset($all_tag_mappings[$tag])) {
        $mapping = $all_tag_mappings[$tag];

        // Add search terms (excluding tags marked as exclude)
        if (!isset($mapping['exclude']) || !$mapping['exclude']) {
          $search_terms = array_merge($search_terms, $mapping['search_terms']);
        }

        // Collect categories
        if (!empty($mapping['category'])) {
          $categories[] = $mapping['category'];
        }

        // Set orientation if specified
        if (isset($mapping['orientation'])) {
          $orientation = $mapping['orientation'];
        }

        // Set colors if specified
        if (isset($mapping['colors'])) {
          $colors = $mapping['colors'];
        }
      } else {
        // For unmapped tags, use them as search terms
        if (!in_array($tag, $exclude_from_query)) {
          $search_terms[] = $tag;
        }
      }
    }

    // Remove duplicates
    $search_terms = array_unique($search_terms);
    $categories = array_unique($categories);

    // If no search terms found, use defaults
    if (empty($search_terms)) {
      $search_terms = ['news', 'background', 'media'];
    }

    // Limit search terms to 5 for better relevance
    $search_terms = array_slice($search_terms, 0, 5);

    // Choose primary category (prioritize specific ones)
    $category = '';
    $category_priority = ['sports', 'fashion', 'food', 'travel', 'nature', 'people', 'business', 'backgrounds'];

    foreach ($category_priority as $priority_cat) {
      if (in_array($priority_cat, $categories)) {
        $category = $priority_cat;
        break;
      }
    }

    // If still no category, use first one or empty
    if (empty($category) && !empty($categories)) {
      $category = $categories[0];
    }

    // Add context-specific boosters
    $boosted_terms = $this->boost_search_terms($search_terms, $query_lower);

    return [
      'search_query' => implode(' ', $boosted_terms),
      'category' => $category,
      'orientation' => $orientation,
      'colors' => $colors
    ];
  }

  /**
   * Boost search terms with context-specific keywords
   */
  private function boost_search_terms($search_terms, $original_tags)
  {
    $boosted = $search_terms;

    // Context boosters based on theme type
    $context_boosters = [
      'news' => ['background', 'texture', 'pattern', 'website'],
      'magazine' => ['cover', 'layout', 'design', 'editorial'],
      'sport' => ['action', 'dynamic', 'energy', 'movement'],
      'fashion' => ['style', 'trend', 'modern', 'contemporary'],
      'technology' => ['digital', 'innovation', 'future', 'tech'],
      'business' => ['professional', 'corporate', 'office', 'success'],
    ];

    // Add boosters based on detected context
    foreach ($context_boosters as $context => $booster_terms) {
      if (in_array($context, $original_tags)) {
        $boosted = array_merge($boosted, $booster_terms);
        break;
      }
    }

    // Always add these generic boosters for better web/theme results
    $boosted[] = 'website';
    $boosted[] = 'background';

    return array_unique($boosted);
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
