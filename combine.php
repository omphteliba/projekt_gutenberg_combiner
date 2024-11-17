<?php

$directory = '.';
$indexFile = $directory . '/index.html';

if (!file_exists($indexFile)) {
    die("index.html not found.");
}

$dom = new DOMDocument();
@$dom->loadHTMLFile($indexFile);

$links = [];
$ul = $dom->getElementsByTagName('ul')->item(0);
if ($ul) {
    foreach ($ul->getElementsByTagName('li') as $li) {
        $a = $li->getElementsByTagName('a')->item(0);
        if ($a) {
            $links[] = $a->getAttribute('href');
        }
    }
}

if (empty($links)) {
    die("No links found in <ul>.");
}

$combinedContent = '';
$chapterTitles = [];
$titlepageContent = '';


// Generate initial index structure (without chapter links yet) BUT DO NOT APPEND IT YET
$indexStart = "<h2 id='toc'>Inhaltsverzeichnis</h2><ul>\n";


// Process titlepage.html first
$titlepagePath = $directory . '/titlepage.html';
$i = 0; // Initialize counter *here* for the first file (titlepage)
if (file_exists($titlepagePath)) {
    $titlepageDom = new DOMDocument();
    @$titlepageDom->loadHTMLFile($titlepagePath);

    $h2Title = $titlepageDom->getElementsByTagName('h2')->item(0);
    if ($h2Title) {
        $chapterTitle = $h2Title->textContent;
        $anchorId = "chapter" . $i++;
        $chapterTitles[$anchorId] = $chapterTitle;

        $startElement = $titlepageDom->getElementsByTagName('h4')->item(0);
        if ($startElement && $startElement->getAttribute('class') == 'subtitle') {
            $content = "";
            $contentNode = $startElement;
            while ($contentNode) {
                if ($contentNode instanceof DOMElement && $contentNode->tagName === 'hr') {
                    break;
                }
                $content .= $titlepageDom->saveHTML($contentNode);
                $contentNode = $contentNode->nextSibling;
            }
            $titlepageContent .= "<div id='" . $anchorId . "'>" . $content . "</div>\n";
        } else {
            // Handle missing subtitle
            $content = "";
            $contentNode = $h2Title;
            while ($contentNode) {
                if ($contentNode instanceof DOMElement && $contentNode->tagName === 'hr') {
                    break;
                }
                $content .= $titlepageDom->saveHTML($contentNode);
                $contentNode = $contentNode->nextSibling;
            }
            $titlepageContent .= "<div id='" . $anchorId . "'>" . $content . "</div>\n";
            echo "Subtitle element not found in titlepage.html, adding everything after H2.\n";
        }
    } else {
        echo "Title (h2) not found in titlepage.html\n";
    }
} else {
    echo "titlepage.html not found.\n";
}



// Add title page entry to index
if (isset($chapterTitles['chapter0'])) {
    $indexStart .= "<li><a href='#chapter0'>" . $chapterTitles["chapter0"] . "</a></li>\n";
}


// Process other chapters *AFTER* the index has started
$i = 1; // Reset counter for content chapters
foreach ($links as $link) {
    if ($link == 'titlepage.html') {
        continue; // Skip titlepage, already processed
    }

    $filePath = $directory . '/' . $link;

    if (file_exists($filePath)) {
        $fileDom = new DOMDocument();
        @$fileDom->loadHTMLFile($filePath);


        $startElement = $fileDom->getElementsByTagName('h3')->item(0);  // Look for h3 first
        if (!$startElement) {
            $startElement = $fileDom->getElementsByTagName('h2')->item(0);  // Then look for h2
             if (!$startElement) {
                 $startElement = $fileDom->getElementsByTagName('h4')->item(0);  // Finally, look for h4.subtitle
                 if ($startElement && $startElement->getAttribute('class') == 'subtitle') {
                      // Use the h2 title if h4.subtitle exists
                      $h2Title = $fileDom->getElementsByTagName('h2')->item(0);
                       if ($h2Title) {
                            $chapterTitle = $h2Title->textContent;
                        } else {
                             echo "Title (h2) not found for subtitle in $filePath\n";
                             continue;
                         }
                } else {
                   $startElement = null; // If neither h2 or h4.subtitle found, skip
                 }
             } else {
               $chapterTitle = $startElement->textContent;
             }
         } else {
            $chapterTitle = $startElement->textContent;
         }

        if ($startElement) {
            $anchorId = "chapter" . $i++;
            $chapterTitles[$anchorId] = $chapterTitle;

            $content = "";
            $contentNode = $startElement;
            while ($contentNode) {
                if ($contentNode instanceof DOMElement && $contentNode->tagName === 'hr') {
                    break;
                }
                $content .= $fileDom->saveHTML($contentNode);
                $contentNode = $contentNode->nextSibling;
            }

            $combinedContent .= "<div id='" . $anchorId . "'>" . $content . "</div>\n";

        } else {
            echo "Starting element (h2, h3, or h4.subtitle) not found in $filePath\n";
        }
    } else {
        echo "File not found: $filePath\n";
    }
}



// Finish and append the rest of the index *after* the chapter loop
$indexEnd = "";
foreach ($chapterTitles as $anchorId => $title) {
    if ($anchorId == 'chapter0') {
        continue; // Skip titlepage, already added
    }
    $indexEnd .= "<li><a href='#" . $anchorId . "'>" . $title . "</a></li>\n";
}
$indexEnd .= "</ul>\n"; // Close <ul>

$combinedContent = $titlepageContent . $indexStart . $indexEnd . $combinedContent; // Append completed index



// Cleanup

$combinedContent = preg_replace('/<!DOCTYPE[^>]*>/', '', $combinedContent);
$combinedContent = preg_replace('/<html[^>]*>/', '', $combinedContent);
$combinedContent = preg_replace('/<body[^>]*>/', '', $combinedContent);
$combinedContent = preg_replace('/<\/html>/', '', $combinedContent);
$combinedContent = preg_replace('/<\/body>/', '', $combinedContent);



$outputContent = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head><body>';
$outputContent .= $combinedContent;
$outputContent .= '</body></html>';


$outputFile = $directory . '/combined.html';
file_put_contents($outputFile, $outputContent);

echo "Combined HTML saved to $outputFile\n";