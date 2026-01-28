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
    $content_category = sanitize_text_field($request->get_param('category')); // ✅ NEW

    // Convert language
    $pixabay_lang = $this->get_pixabay_language($lang);

    // Words to exclude
    $exclude_words = ['free', 'pro', 'child'];

    // Split query into array and trim spaces
    $query_array = array_map('trim', explode(',', $query));

    // Remove excluded words
    $query_array = array_filter($query_array, function ($item) use ($exclude_words) {
      foreach ($exclude_words as $word) {
        if (stripos($item, $word) !== false) {
          return false;
        }
      }
      return true;
    });

    $query_string = implode(' ', $query_array);

    // ✅ Enhance based on content category (pet, business, etc.)
    $category_data = $this->enhance_query_by_category($query_string, $content_category);
    $enhanced_query = $category_data['query'];
    $pixabay_category = $category_data['category'];

    // ✅ Further enhance with language context if needed
    $enhanced_query = $this->enhance_query_with_language_context($enhanced_query, $lang);

    // Ensure max 100 characters
    if (strlen($enhanced_query) > 100) {
      $enhanced_query = substr($enhanced_query, 0, 100);
    }

    // If no category from content type, detect from query
    if (empty($pixabay_category)) {
      $pixabay_category = $this->detect_category($enhanced_query);
    }

    // Build parameters
    $params = [
      'key'         => $this->pixabay_api_key,
      'q'           => urlencode($enhanced_query),
      'image_type'  => 'photo',
      'lang'        => $pixabay_lang,
      'orientation' => 'horizontal',
      'order'       => 'popular',
      'per_page'    => 50,
      'safesearch'  => 'true',
    ];

    if (!empty($pixabay_category)) {
      $params['category'] = $pixabay_category;
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
   * ✅ Enhanced query based on content category with ALL categories
   */
  private function enhance_query_by_category($query, $content_category)
  {
    $query_lower = strtolower($query);
    $category_lower = strtolower($content_category);

    $category_config = [
      'pet' => [
        'keywords' => ['pet', 'animal', 'dog', 'cat', 'puppy', 'kitten'],
        'pixabay_category' => 'animals',
        'boost_terms' => [
          'news' => 'pet news animal',
          'magazine' => 'pet magazine animal care',
          'care' => 'pet care veterinary',
          'training' => 'pet training dog cat',
        ]
      ],
      'business' => [
        'keywords' => ['business', 'professional', 'office', 'corporate', 'meeting'],
        'pixabay_category' => 'business',
        'boost_terms' => [
          'news' => 'business news professional',
          'magazine' => 'business magazine corporate',
          'meeting' => 'business meeting office',
          'team' => 'business team professional',
        ]
      ],
      'ecommerce' => [
        'keywords' => ['shopping', 'ecommerce', 'online store', 'retail', 'commerce'],
        'pixabay_category' => 'business',
        'boost_terms' => [
          'news' => 'ecommerce shopping online',
          'magazine' => 'retail magazine shopping',
          'store' => 'online store ecommerce',
          'cart' => 'shopping cart online',
        ]
      ],
      'lifestyle' => [
        'keywords' => ['lifestyle', 'living', 'wellness', 'daily life', 'health'],
        'pixabay_category' => 'people',
        'boost_terms' => [
          'news' => 'lifestyle wellness living',
          'magazine' => 'lifestyle magazine wellness',
          'health' => 'healthy lifestyle wellness',
          'home' => 'lifestyle home living',
        ]
      ],
      'personal' => [
        'keywords' => ['personal', 'individual', 'portrait', 'people', 'person'],
        'pixabay_category' => 'people',
        'boost_terms' => [
          'news' => 'personal story people',
          'magazine' => 'personal lifestyle people',
          'blog' => 'personal blog portrait',
          'story' => 'personal story individual',
        ]
      ],
      // ✅ NEW CATEGORIES
      'food' => [
        'keywords' => ['food', 'cuisine', 'cooking', 'restaurant', 'dish', 'meal'],
        'pixabay_category' => 'food',
        'boost_terms' => [
          'news' => 'food cuisine culinary',
          'magazine' => 'food magazine culinary',
          'restaurant' => 'restaurant food dining',
          'recipe' => 'recipe cooking food',
          'chef' => 'chef cooking professional',
        ]
      ],
      'covid' => [
        'keywords' => ['covid', 'pandemic', 'coronavirus', 'virus', 'health crisis'],
        'pixabay_category' => 'health',
        'boost_terms' => [
          'news' => 'covid pandemic health',
          'vaccine' => 'covid vaccine medical',
          'safety' => 'covid safety health',
          'hospital' => 'covid hospital medical',
          'mask' => 'face mask covid',
        ]
      ],
      'real estate' => [
        'keywords' => ['real estate', 'property', 'house', 'home', 'building', 'architecture'],
        'pixabay_category' => 'buildings',
        'boost_terms' => [
          'news' => 'real estate property housing',
          'magazine' => 'real estate magazine property',
          'market' => 'real estate market housing',
          'house' => 'house home property',
          'apartment' => 'apartment building real estate',
        ]
      ],
      'dental' => [
        'keywords' => ['dental', 'dentist', 'teeth', 'oral health', 'smile'],
        'pixabay_category' => 'health',
        'boost_terms' => [
          'news' => 'dental health teeth',
          'clinic' => 'dental clinic dentist',
          'care' => 'dental care oral health',
          'smile' => 'smile teeth dental',
          'treatment' => 'dental treatment clinic',
        ]
      ],
      'portfolio' => [
        'keywords' => ['portfolio', 'design', 'creative', 'work', 'showcase'],
        'pixabay_category' => 'business',
        'boost_terms' => [
          'news' => 'portfolio work professional',
          'design' => 'design portfolio creative',
          'creative' => 'creative portfolio design',
          'work' => 'portfolio work showcase',
          'project' => 'portfolio project design',
        ]
      ],
      'gadgets' => [
        'keywords' => ['gadget', 'technology', 'device', 'electronics', 'tech'],
        'pixabay_category' => 'computer',
        'boost_terms' => [
          'news' => 'gadget technology tech',
          'magazine' => 'gadget magazine technology',
          'review' => 'gadget review technology',
          'phone' => 'smartphone gadget technology',
          'device' => 'device gadget electronics',
        ]
      ],
      'lawyer' => [
        'keywords' => ['lawyer', 'legal', 'law', 'attorney', 'justice', 'court'],
        'pixabay_category' => 'business',
        'boost_terms' => [
          'news' => 'lawyer legal law',
          'office' => 'lawyer office legal',
          'court' => 'court law legal',
          'justice' => 'justice law legal',
          'attorney' => 'attorney lawyer legal',
        ]
      ],
      'health' => [
        'keywords' => ['health', 'medical', 'healthcare', 'wellness', 'doctor'],
        'pixabay_category' => 'health',
        'boost_terms' => [
          'news' => 'health medical healthcare',
          'magazine' => 'health magazine wellness',
          'doctor' => 'doctor medical healthcare',
          'hospital' => 'hospital medical healthcare',
          'care' => 'healthcare medical wellness',
        ]
      ],
      'education' => [
        'keywords' => ['education', 'school', 'learning', 'student', 'teacher', 'university'],
        'pixabay_category' => 'education',
        'boost_terms' => [
          'news' => 'education school learning',
          'magazine' => 'education magazine school',
          'student' => 'student education learning',
          'teacher' => 'teacher education school',
          'university' => 'university education college',
        ]
      ],
      'industry' => [
        'keywords' => ['industry', 'manufacturing', 'factory', 'production', 'industrial'],
        'pixabay_category' => 'industry',
        'boost_terms' => [
          'news' => 'industry manufacturing production',
          'magazine' => 'industry magazine manufacturing',
          'factory' => 'factory industry manufacturing',
          'production' => 'production industry manufacturing',
          'worker' => 'industry worker manufacturing',
        ]
      ],
    ];

    if (!isset($category_config[$category_lower])) {
      return [
        'query' => $query,
        'category' => ''
      ];
    }

    $config = $category_config[$category_lower];

    // Check if query already has category context
    $has_context = false;
    foreach ($config['keywords'] as $keyword) {
      if (stripos($query_lower, $keyword) !== false) {
        $has_context = true;
        break;
      }
    }

    $enhanced_query = $query;

    // If no context, add it
    if (!$has_context) {
      // Check for boost terms (more specific matching)
      $boost_applied = false;
      foreach ($config['boost_terms'] as $trigger => $boosted) {
        if (stripos($query_lower, $trigger) !== false) {
          $enhanced_query = $boosted;
          $boost_applied = true;
          break;
        }
      }

      // If no boost term matched, just add main category keyword
      if (!$boost_applied) {
        $enhanced_query = $query . ' ' . $config['keywords'][0];
      }
    }

    return [
      'query' => trim($enhanced_query),
      'category' => $config['pixabay_category']
    ];
  }

  /**
   * ✅ Updated detect_category with new categories
   */
  private function detect_category($query)
  {
    $query_lower = strtolower($query);

    $category_keywords = [
      'animals' => ['pet', 'dog', 'cat', 'animal', 'puppy', 'kitten', 'bird', 'wildlife'],
      'business' => ['news', 'media', 'office', 'meeting', 'professional', 'business', 'corporate', 'ecommerce', 'shopping', 'lawyer', 'legal', 'portfolio'],
      'people' => ['portrait', 'person', 'people', 'lifestyle', 'personal', 'reporter', 'journalist', 'individual'],
      'places' => ['china', 'japan', 'india', 'nepal', 'location', 'regional', 'local', 'city', 'country'],
      'nature' => ['nature', 'landscape', 'outdoor', 'mountain', 'forest', 'environment'],
      'food' => ['food', 'cuisine', 'cooking', 'restaurant', 'dish', 'meal', 'recipe', 'chef'],
      'health' => ['health', 'wellness', 'medical', 'fitness', 'doctor', 'hospital', 'covid', 'pandemic', 'dental', 'dentist'],
      'buildings' => ['real estate', 'property', 'house', 'home', 'building', 'architecture', 'apartment'],
      'computer' => ['gadget', 'technology', 'device', 'electronics', 'tech', 'smartphone', 'digital'],
      'education' => ['education', 'school', 'learning', 'student', 'teacher', 'university', 'college'],
      'industry' => ['industry', 'manufacturing', 'factory', 'production', 'industrial', 'worker'],
      'transportation' => ['car', 'vehicle', 'transport', 'travel', 'road'],
      'sports' => ['sport', 'fitness', 'exercise', 'gym', 'athlete'],
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
   * ✅ Add language context
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
    ];

    if (isset($language_keywords[$lang_lower])) {
      $has_language = false;
      foreach ($language_keywords[$lang_lower] as $keyword) {
        if (stripos($query_lower, $keyword) !== false) {
          $has_language = true;
          break;
        }
      }

      if (!$has_language) {
        $query .= ' ' . $language_keywords[$lang_lower][0];
      }
    }

    return trim($query);
  }

  /**
   * ✅ Map languages
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
