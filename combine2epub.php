<?php
require 'vendor/autoload.php'; // Assuming you've installed grandt/phpepub via Composer

use PHPePub\Core\EPub;
use PHPePub\Core\EPubChapterSplitter;
use PHPePub\Core\Logger;
use PHPePub\Core\Structure\OPF\DublinCore;
use PHPePub\Helpers\URLHelper;
use PHPZip\Zip\File\Zip;

function combineHtmlFiles($directory = '.') {
    $indexFile = $directory . '/index.html';

    if (!file_exists($indexFile)) {
        return "index.html not found.";
    }

    $dom = new DOMDocument();
    @$dom->loadHTMLFile($indexFile);

    // Get author and title from index.html meta tags FIRST
    $author = "";
    $bookTitle = "";

    $metaTags = $dom->getElementsByTagName('meta');
    $metaTagString = ""; // Initialize as empty string
    foreach ($metaTags as $metaTag) {
        $metaTagString .= $dom->saveHTML($metaTag); // Concatenate meta tags
        if ($metaTag->getAttribute('name') === 'author') {
            $author = $metaTag->getAttribute('content');
        } elseif ($metaTag->getAttribute('name') === 'title') {
            $bookTitle = $metaTag->getAttribute('content');
        }
    }

    $titlepagePath = $directory . '/titlepage.html';
    // If meta tags not found, try titlepage.html as fallback
    if (empty($author) || empty($bookTitle)) {
        if (file_exists($titlepagePath)) {
            $titlepageDom = new DOMDocument();
            @$titlepageDom->loadHTMLFile($titlepagePath);

            if (empty($author)) {
                $authorElement = $titlepageDom->getElementsByTagName('h3')->item(0);
                if ($authorElement && $authorElement->getAttribute('class') === 'author') {
                    $author = $authorElement->textContent;
                } else {
                    echo "Author element (h3.author) not found in titlepage.html.\n";
                }
            }

            if (empty($bookTitle)) {
                $titleElement = $titlepageDom->getElementsByTagName('h2')->item(0);
                if ($titleElement && $titleElement->getAttribute('class') === 'title') {
                    $bookTitle = $titleElement->textContent;
                } else {
                    echo "Title element (h2.title) not found in titlepage.html.\n";
                }
            }

        } else {
            echo "titlepage.html not found.\n";
        }
    }


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
        return "No links found in <ul>.";
    }


    $combinedContent = '';
    $chapterTitles = [];
    $titlepageContent = '';
    $indexStart = "<h2>Inhaltsverzeichnis</h2><ul>\n";

    $i = 0;
    if (file_exists($titlepagePath)) {
        $titlepageContent = processChapter($titlepagePath, $i, $chapterTitles);
    }

    if (isset($chapterTitles['chapter0'])) {
        $indexStart .= "<li><a href='#chapter0'>" . $chapterTitles["chapter0"] . "</a></li>\n";
    }

    $i = 1;
    foreach ($links as $link) {
        if ($link == 'titlepage.html') {
            continue;
        }

        $filePath = $directory . '/' . $link;
        if (file_exists($filePath)) {
            $combinedContent .= processChapter($filePath, $i, $chapterTitles);
        } else {
            echo "File not found: $filePath\n";
        }
    }

    $indexEnd = "";
    foreach ($chapterTitles as $anchorId => $title) {
        if ($anchorId == 'chapter0') {
            continue;
        }
        $indexEnd .= "<li><a href='#" . $anchorId . "'>" . $title . "</a></li>\n";
    }
    $indexEnd .= "</ul>\n";

    $combinedContent = '<article>' . $combinedContent . '</article>';
    $combinedContent = $titlepageContent . '<nav id="toc">' . $indexStart . $indexEnd . '</nav>' . $combinedContent;

    $combinedContent = cleanupHtml($combinedContent);

    $outputContent = '<html><head>';
    $outputContent .= $metaTagString;  // Add the collected meta tags
    $outputContent .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    $outputContent .= '<title>' . $bookTitle . '</title>';
    $outputContent .= '</head><body>';
    $outputContent .= $combinedContent;
    $outputContent .= '</body></html>';


    $filename = sanitizeFileNameForEPub($author . "_-_" . $bookTitle . ".html");
    $outputFile = $directory . '/' . $filename;
    file_put_contents($outputFile, $outputContent);

    return "Combined HTML saved to $outputFile\n";
}



function processChapter($filePath, &$i, &$chapterTitles) {
    $fileDom = new DOMDocument();
    @$fileDom->loadHTMLFile($filePath);

     $startElement = $fileDom->getElementsByTagName('h3')->item(0); 
     if (!$startElement) {
        $startElement = $fileDom->getElementsByTagName('h2')->item(0);
        if (!$startElement) {
            $startElement = $fileDom->getElementsByTagName('h4')->item(0);
            if ($startElement && $startElement->getAttribute('class') == 'subtitle') {
                $h2Title = $fileDom->getElementsByTagName('h2')->item(0);
                if ($h2Title) {
                    $chapterTitle = $h2Title->textContent;
                } else {
                    echo "Title (h2) not found for subtitle in $filePath\n";
                    return "";
                }

            }  else {
                  return ""; // Skip if no suitable title is found
                }
         }  else {
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

        return "<section id='" . $anchorId . "'>" . $content . "</section>\n";
    }
    return '';
}



function cleanupHtml($html) {
    $html = preg_replace('/<!DOCTYPE[^>]*>/', '', $html);
    $html = preg_replace('/<html[^>]*>/', '', $html);
    $html = preg_replace('/<body[^>]*>/', '', $html);
    $html = preg_replace('/<\/html>/', '', $html);
    $html = preg_replace('/<\/body>/', '', $html);
    return $html;
}


// New helper function to sanitize the filename
function sanitizeFileNameForEPub($filename) {
    $filename = str_replace([' ', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $filename);  // Replace unsafe characters
    $filename = preg_replace('/_+/', '_', $filename); // Replace multiple underscores with single underscore
    return $filename;
}

echo combineHtmlFiles();
