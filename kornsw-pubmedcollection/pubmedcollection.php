<?php

/**
 * Plugin Name: PubMed-Collection
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-PubmedCollection/master/doc/kornsw-pubmedcollection.update.json
 * Plugin URI: https://github.com/KornSW/WP-PubmedCollection
 * Description: Includes PubMed Shortcode: [include_pubmed collection="64421242"]
 * Version: 1.0.3
 * Author: KornSW
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

  
/*************** SELF-UPDATE ***************/
define( 'KSWPUBMEDCOLLEC9EBD_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswpubmedcollec9ebd_bootstrap( __FILE__ );
/*******************************************/

add_shortcode('include_pubmed', 'include_pubmed_shortcode');

  function include_pubmed_shortcode($atts) {
  
    $atts = array_change_key_case((array) $atts, CASE_LOWER);
    
    $mdic_atts = shortcode_atts(array(
      'collection' => '0',
      'highlightAuthor' => 'Korn S'
    ), $atts);
    
    $collection = $mdic_atts['collection']; 
    $highlightAuthor = $mdic_atts['highlightAuthor'];
    
    // URL der Zielseite
    $url = 'https://pubmed.ncbi.nlm.nih.gov/collections/'.$collection.'/?sort=pubdate&size=200';
       
    //return 'Collection: '.$collection.'<br>Author: '.$highlightAuthor.'<br>URL: '.$url;
  
    // HTML-Code der Seite laden
    $html = file_get_contents($url);
 
    // DOMDocument zum Parsen des HTML-Codes verwenden
    $doc = new DOMDocument();
    libxml_use_internal_errors(true); // Fehler unterdr�cken, die durch schlechtes HTML entstehen k�nnten
    $doc->loadHTML($html);
    libxml_clear_errors();
    
    // XPath verwenden, um die gew�nschten Artikel-Elemente zu finden
    $xpath = new DOMXPath($doc);
    
    // Alle Artikel mit der Klasse "full-docsum" finden
    $articles = $xpath->query('//article[contains(@class, "full-docsum")]');
    
    $outputContent = '*Auszug aus dem <b>PubMed</b>-Register<br>(<a href="'.$url.'" target="_blank">https://pubmed.ncbi.nlm.nih.gov</a>)</a>:<br><br><hr>';
    
    // Start der Ausgabe einer einfachen HTML-Tabelle
    //$outputContent = $outputContent.'<table border="1">';
    //$outputContent = $outputContent.'<tr><th>Title</th><th>Link</th><th>PMID</th><th>Date</th><th>Authors</th></tr>';
    
    // Durch die gefundenen Artikel iterieren und die Informationen extrahieren
    foreach ($articles as $article) {
    
      // Titel extrahieren
      $titleNode = $xpath->query('.//a[@class="docsum-title"]', $article);
      $title = $titleNode->length > 0 ? trim($titleNode[0]->textContent) : 'N/A';
    
      // Link extrahieren
      $linkNode = $xpath->query('.//a[@class="docsum-title"]', $article);
      $link = $linkNode->length > 0 ? 'https://pubmed.ncbi.nlm.nih.gov' . $linkNode[0]->getAttribute('href') : '#';
    
      // PMID extrahieren
      $pmidNode = $xpath->query('.//span[@class="docsum-pmid"]', $article);
      $pmid = $pmidNode->length > 0 ? trim($pmidNode[0]->textContent) : 'N/A';
    
      // Datum extrahieren und parsen
      $dateNode = $xpath->query('.//span[@class="docsum-journal-citation full-journal-citation"]', $article);
      if ($dateNode->length > 0) {
        $date = trim($dateNode[0]->textContent);
        $dateParts1 = explode(';', $date); // Teile den String am Semikolon
        $dateParts2 = explode(':', $dateParts1[0]); // Teile den String am doppelpunkt
        $date = trim($dateParts2[0]); // Verwende nur den Teil vor dem ersten Semikolon
      } else {
        $date = 'N/A';
      }
    
      // Autorenliste extrahieren
      $authorNode = $xpath->query('.//span[@class="docsum-authors full-authors"]', $article);
      if ($authorNode->length > 0) {
        $authors = trim($authorNode[0]->textContent);
        // "Korn" in Fettschrift markieren
        $authors = str_replace($highlightAuthor, '<span style="color: blue"><b>'.$highlightAuthor.'</b></span>', $authors);
      } else {
        $authors = 'N/A';
      }
    
      // Artikel in der Tabelle anzeigen
      
      //$outputContent = $outputContent.'<br><b>'.$date.'</b><br><a href="'.$link.'" target="_blank">'.$title.'</a>'.'<br><span style="font-size: 8pt"><b>Authors:</b> '.$authors.'</span><br>';
      
      $outputContent = $outputContent.'<br><a href="'.$link.'" target="_blank"><b>'.$title.'</b></a>'.'<br><span style="font-size: 11pt">'.$date.'</span><br><span style="font-size: 8pt; display: inline-block;"><b>Authors:</b> '.$authors.'</span><br>';
      
      //$outputContent = $outputContent.'<tr>';
      //$outputContent = $outputContent.'<td><a href="'.$link.'">'.$title.'</a></td>';
      //$outputContent = $outputContent.'<td>'.$pmid.'</td>';
      //$outputContent = $outputContent.'<td>'.$date.'</td>';
      //$outputContent = $outputContent.'<td>'.$authors.'</td>';
      //$outputContent = $outputContent.'</tr>';
      
    }
    
    // Tabelle schlie�en
    //$outputContent = $outputContent.'</table>';
    
    return '<!--\n       OUTPUT OF PUBMED INCLUDE 1.0.2\n\n       FROM: '.$url.'\n\n-->'.$outputContent;
    
  }
  
?>