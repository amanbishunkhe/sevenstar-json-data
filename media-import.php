<?php

$mediajsonFile = get_template_directory_uri().'/media.json';
$media_json_data = file_get_contents($mediajsonFile);
$media_data = json_decode($media_json_data, true); 
// echo '<pre>';
// print_r( $media_data[2]['data'] );
// echo '</pre>';

// "model_type": "App\\Models\\BackendModels\\Post"
// https://sevenstarkhabar.com/storage/4737/conversions/mata-thumb_840_560.jpg

$post_image_data = array();

foreach ($media_data[2]['data'] as $key => $value) {    
    if( isset( $value['model_type'] ) && $value['model_type'] == 'App\\Models\\BackendModels\\Post' ) {
        $media_id = $value['id'];        
        $post_id = $value['model_id'];
        $post_ref_name = $value['name'];  
        $image_source = "https://sevenstarkhabar.com/storage/{$media_id}/conversions/{$post_ref_name}-thumb_1200_600.jpg";
        
        $post_image_data[] = array( "post_id" => $post_id, "image_source" => $image_source );
    }   
}

// echo '<pre>';
// print_r( $post_image_data );
// echo '</pre>';


// Similarly for post data

// $postJsonFile = get_template_directory_uri().'/posts.json';
// $post_json_data = file_get_contents($postJsonFile);
// $post_data = json_decode($post_json_data, true); 
// $posts = $post_data[2]['data'];

// echo '<pre>';
// print_r( $posts );
// echo '</pre>';

// foreach ($posts as $key => $post) {
//     foreach ($post_image_data as $key => $image) {
//         if( (string)$image['post_id'] === ( string)$post['id'] ) {          
//             $post['image_source'] = $image['image_source'];
//             break;
//         }
//     }  

// }

// unset($post);

// // Save merged data as JSON
// file_put_contents(__DIR__ . '/output.json', json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// // Save as CSV
// $csvFile = fopen(__DIR__ . '/output.csv', 'w');
// if (!empty($posts)) {
//     // Write CSV headers
//     fputcsv($csvFile, array_keys($posts[0]));
//     // Write rows
//     foreach ($posts as $post) {
//         fputcsv($csvFile, $post);
//     }
// }
// fclose($csvFile);

// echo "JSON and CSV files generated.\n";

// Load post data from JSON file
$postJsonFile = get_template_directory_uri().'/posts.json';
$post_json_data = file_get_contents($postJsonFile);
if ($post_json_data === false) {
    die("Error: Failed to read posts.json");
}
$post_data = json_decode($post_json_data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON in posts.json - " . json_last_error_msg());
}
$posts = $post_data[2]['data'] ?? [];
if (empty($posts)) {
    die("Error: No posts data found in posts.json");
}

// Debug: Print initial data
// echo "Initial post_image_data:\n";
// print_r($post_image_data);
// echo "Initial posts data:\n";
// print_r($posts);

// Merge image_source into posts
$match_count = 0;
foreach ($posts as $key => &$post) {
    foreach ($post_image_data as $image) {
       
        if ((string)$image['post_id'] === (string)$post['id']) {
            $post['image_source'] = $image['image_source'];
            $match_count++;
          //  echo "Match found: Post ID {$post['id']} -> Image {$image['image_source']}\n";
            break;
        }
    }
}
echo $match_count;
unset($post); // Unset the reference to avoid issues

// Debug: Print final posts data
// echo "Final posts data with image_source:\n";
// print_r($posts);
// echo "Total matches: $match_count\n";

// Save merged data as JSON
// file_put_contents(__DIR__ . '/output.json', json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents(
    __DIR__ . '/output.json',
    json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// Save as CSV
$csvFile = fopen(__DIR__ . '/output.csv', 'w');
if ($csvFile === false) {
    die("Error: Failed to open output.csv for writing");
}
if (!empty($posts)) {
    // Write CSV headers
    fputcsv($csvFile, array_keys($posts[0]));
    // Write rows
    foreach ($posts as $post) {
        fputcsv($csvFile, $post);
    }
}
fclose($csvFile);

echo "JSON and CSV files generated successfully.\n";
echo $match_count;
?>