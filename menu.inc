<?php
$menuEntries = array(
    array(
        'text' => 'Air Defender',
        'type' => 'content',
        'page' => 'www/index.php',
        'wrap' => 1
    ),
    array(
        'text' => 'Air Defender - Help',
        'type' => 'help',
        'page' => 'www/help.php',
        'wrap' => 1
    )
);

foreach ($menuEntries as $entry) {
    if ($entry['type'] != $menu)
        continue;

    printf(
        "<li><a href='plugin.php?plugin=%s&page=%s'>%s</a></li>\n",
        $plugin,
        $entry['page'],
        $entry['text']
    );
}
?>
