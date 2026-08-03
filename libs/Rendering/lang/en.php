<?php

declare(strict_types=1);

// The core catalogue: keys the rendering lib's own templates use. Modules
// contribute their own files, and later contributions override these.
return [
    'error.title' => 'Something went wrong',
    'error.sign_in' => 'Go to the sign-in page',

    // The list furniture. These were three identical copies under
    // thirdparty., invoice. and order. — the same English, translated three
    // times forever. They live here now, with the macros that render them; a
    // module that needs different words still overrides the key.
    'list.search' => 'Search',
    'list.search_submit' => 'Search',
    'list.filters' => 'Filters',
    'list.filter_any' => 'Any',
    'list.apply' => 'Apply',
    'list.clear' => 'Clear',
    'list.sort_by' => 'Sort by %column%',
    'list.page_of' => 'Page %page% of %pages% (%total% total)',
    'list.previous' => 'Previous',
    'list.next' => 'Next',
    'list.no_match' => 'Nothing matches those criteria.',
];
