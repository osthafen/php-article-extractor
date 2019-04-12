<?php

namespace Osthafen\ArticleExtractor;

use Goose\Client as GooseClient;

use Curl\Curl;

use andreskrey\Readability\Readability;
use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;

use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\HtmlNode;
use PHPHtmlParser\Dom\TextNode;

use LanguageDetection\Language;

class ArticleExtractor {

	// Debug flag - set to true for convenience during development
	private $debug = true;

	// Valid root elements we want to search for
	private $valid_root_elements = [ 'body', 'form', 'main', 'div', 'ul', 'li', 'table', 'span', 'section', 'article', 'main'];

	// Elements we want to place a space in front of when converting to text
	private $space_elements = ['p', 'li'];

  /**
   * Provided for backward compatibility.
   */
  public function getArticleText($url) {
    return $this->processURL($url);
  }

	/**
	 * The only public function for this class. processURL returns the best guess
   * of the human readable part of a URL, as well as additional meta data
   * associated with the parsing.
	 *
	 * Returns an array with the following information:
	 *
	 * [
	 *	  title => (the title of the article)
	 *	  text => (the human readable piece of the article)
	 *	  parse_method => (the internal processing method used to parse the article, "goose", "custom", "readability"
	 *	  language => (the ISO 639-1 code detected for the language)
	 *	  language_method => (the way the language was detected)
	 * ]
   *
   * This processing will attempt to use the following methods in this order:
   *   1. Readability
   *   2. Goose
   *   3. Goose with some additional custom processing
   *
	 */
	public function processURL($url) {

		$results = [];

		$results['text'] == null;
		$results['language'] = null;
		$results['language_method'] = null;
		$results['html'] = null;

		$curl = $this->checkMetaRefresh($url);

		if (!$curl) return $results;

		$url = $curl->getInfo(CURLINFO_EFFECTIVE_URL); //get redirected url

		$content = $curl->response;

		$curl->close();


		$this->log_debug("Attempting to parse " . $url);

		// First attempt to parse the URL into the structure we want
		$results = $this->parseViaReadability($content);

		// If we don't see what we want, try our other method
		if ($results['text'] == null) {
		  $results = $this->parseViaGooseOrCustom($url, $content);
		}

		// If we still don't havewhat we want, return what we have
		if ($results['text'] == null) {
		  unset($results['html']); // remove raw HTML before returning it
		  return $results;
		}

    // Otherwise, continue on...

    // Implement check in HTML to determine if the language is specified somewhere
		if ($lang_detect = $this->checkHTMLForLanguageHint($results['html'])) {
			$results['language_method'] = "html";
			$results['language'] = $lang_detect;
			$this->log_debug("Language was detected as " . $results['language'] . " from HTML");
		}

		$this->log_debug("--------- PRE UTF 8 CLEANING -------------------------------------");
		$this->log_debug("title: " . $results['title']);
		$this->log_debug("text: " . $results['text']);
		$this->log_debug("------------------------------------------------------------------");

		// Convert items to UTF-8
		$results['title'] = $this->shiftEncodingToUTF8($results['title']);
		$results['text'] = $this->shiftEncodingToUTF8($results['text']);

		// If we've got some text, we still don't have a languag
		if ($results['text'] != null && !isset($results['language']) ) {

			$languageDetector = new Language;

			$language = $languageDetector->detect(mb_substr($results['text'],0,500))->bestResults()->close();

			if (is_array($language)) {

				$language = key($language);

			}

			// Then use the patrickschur class to detect the language
			$results['language_method'] = "class";
			$results['language'] = $language;
			$this->log_debug("Language was detected as  " . $language . " from class");
		}
		else {
			$this->log_debug("Skipping class language detection");
      		//$results['language_method'] = null;
      		//$results['language'] = null;
		}

		$this->log_debug("text: " . $results['text']);
		$this->log_debug("title: " . $results['title']);
		$this->log_debug("language: " . $results['language']);
		$this->log_debug("parse_method: " . $results['parse_method']);
		$this->log_debug("language_method: " . $results['language_method']);

    unset($results['html']); // remove raw HTML before returning it

    return $results;

  }

  /**
	 * Attempts to parse via the Readability libary aReturns the following array.
   * [
   *    'method' => "readability"
   *    'title' => <the title of the article>
   *    'text' => <the cleaned text of the article> | null
   *    'html' => <the raw HTML of the article>
   * ]
   *
   * Parsing can be considered unavailable if 'text' is returned as null
	 */
  private function parseViaReadability($content) {

    $text = null;
    $title = null;
	$method = "readability";

    $readability = new Readability(new Configuration(['SummonCthulhu'=>true]));

    try {

      $readability->parse($content);
      $title = $readability->getTitle();
      $text = $readability->getContent();
      $text = strip_tags($text); // Remove all HTML tags
      $text = html_entity_decode($text); // Make sure we have no HTML entities left over
      //$text = str_replace("\r\r", "\r", $text); // remove carriage returns
      //$text = str_replace("\n\n", "\n", $text); // remove excessive line returns

    }
    catch (ParseException $e) {
      $this->log_debug('parseViaReadability: Error processing text', $e->getMessage());
    }

    return ['parse_method'=>$method, 'title'=>$title, 'text'=>$text, 'html'=>$content];

  }

  /**
	 * Attempts to parse via the Goose libary and our custom processing. Returns the
   * following array.
   * [
   *    'method' => "goose" | "custom" | null
   *    'title' => <the title of the article>
   *    'text' => <the cleaned text of the article> | null
   *    'html' => <the raw HTML of the article>
   * ]
   *
   * Parsing can be considered unavailable if 'text' is returned as null
	 */
  private function parseViaGooseOrCustom($url, $content) {

    $text = null;
    $method = "goose";
    $title = null;
    $html = null;

    $this->log_debug("Parsing via: goose method");

		// Try to get the article using Goose first
		$goose = new GooseClient(['image_fetch_best' => false]);

    try {
      $article = $goose->extractContent($url, $content);
      $title = $article->getTitle();
      $html = $article->getRawHtml();

      // If Goose failed
  		if ($article->getCleanedArticleText() == null) {

  			// Get the HTML from goose
  			$html_string = $article->getRawHtml();

  			//$this->log_debug("---- RAW HTML -----------------------------------------------------------------------------------");
  			//$this->log_debug($html_string);
  			//$this->log_debug("-------------------------------------------------------------------------------------------------");

  			// Ok then try it a different way
  			$dom = new Dom;
  			$dom->load($html_string, ['whitespaceTextNode' => false]);

  			// First, just completely remove the items we don't even care about
  			$nodesToRemove = $dom->find('script, style, header, footer, input, button, aside, meta, link');

  			foreach($nodesToRemove as $node) {
  				$node->delete();
  				unset($node);
  			}

  			// Records to store information on the best dom element found thusfar
  			$best_element = null;
  			$best_element_wc = 0;
  			$best_element_wc_ratio = -1;

        // $html = $dom->outerHtml;

  			// Get a list of qualifying nodes we want to evaluate as the top node for content
  			$candidateNodes = $this->buildAllNodeList($dom->root);
  			$this->log_debug("Candidate node count: " . count($candidateNodes));

  			// Find a target best element
  			foreach($candidateNodes as $node) {

  				// Calculate the wordcount, whitecount, and wordcount ratio for the text within this element
  				$this_element_wc = str_word_count($node->text(true));
  				$this_element_whitecount = substr_count($node->text(true), ' ');
  				$this_element_wc_ratio = -1;

  				// If the wordcount is not zero, then calculation the wc ratio, otherwise set it to -1
  				$this_element_wc_ratio = ($this_element_wc == 0) ? -1 : $this_element_whitecount / $this_element_wc;

  				// Calculate the word count contribution for all children elements
  				$children_wc = 0;
  				$children_num = 0;
  				foreach($node->getChildren() as $child) {
  					if (in_array($child->tag->name(),$this->valid_root_elements)) {
  						$children_num++;
  						$children_wc += str_word_count($child->text(true));
  					}
  				}

  				// This is the contribution for this particular element not including the children types above
  				$this_element_wc_contribution = $this_element_wc - $children_wc;

  				// Debug information on this element for development purposes
  				$this->log_debug("Element:\t". $node->tag->name() . "\tTotal WC:\t" . $this_element_wc . "\tTotal White:\t" . $this_element_whitecount . "\tRatio:\t" . number_format($this_element_wc_ratio,2) . "\tElement WC:\t" . $this_element_wc_contribution . "\tChildren WC:\t" . $children_wc . "\tChild Contributors:\t" . $children_num . "\tBest WC:\t" . $best_element_wc . "\tBest Ratio:\t" . number_format($best_element_wc_ratio,2) . " " . $node->getAttribute('class'));

  				// Now check to see if this element appears better than any previous one

  				// We do this by first checking to see if this element's WC contribution is greater than the previous
  				if	($this_element_wc_contribution > $best_element_wc) {

  					// If we so we then calculate the improvement ratio from the prior best and avoid division by 0
  					$wc_improvement_ratio = ($best_element_wc == 0) ? 100 : $this_element_wc_contribution / $best_element_wc;

  					// There are three conditions in which this candidate should be chosen
  					//		1. The previous best is zero
  					//		2. The new best is more than 10% greater WC contribution than the prior best
  					//		3. The new element wc ratio is less than the existing best element's ratio

  					if ( $best_element_wc == 0 || $wc_improvement_ratio	 >= 1.10 || $this_element_wc_ratio <= $best_element_wc_ratio) {
  						$best_element_wc = $this_element_wc_contribution;
  						$best_element_wc_ratio = $this_element_wc_ratio;
  						$best_element = $node;
  						$this->log_debug("\t *** New best element ***");
  					}
  				}
  			}

  			// If we have a candidate element
  			if ($best_element) {

  				// Now we need to do some sort of peer analysis
  				$best_element = $this->peerAnalysis($best_element);

          /*
  				// Add space before HTML elements that if removed create concatenation issues (e.g. <p>, <li>)
  				$nodesToEditText = $best_element->find('p, li');

  				foreach($nodesToEditText as $node) {
  					$node->setText(" " . $node->text);
  				}

          */
  				//
  				// Decode the text
          //$text = html_entity_decode($best_element->text(true));
  				$text = html_entity_decode($this->getTextForNode($best_element));

  				// Set the method so the caller knows which one was used
  				$method = "custom";
  			}
  			else {
  				$method = null;
  			}
  		}
  		else {
  			$text = $article->getCleanedArticleText();
  		}

    }
    catch (\Exception $e) {
      $this->log_debug('parseViaGooseOrCustom: Unable to request url ' . $url . " due to " . $e->getMessage());
    }

    return ['parse_method'=>$method, 'title'=>$title, 'text'=>$text, 'html'=>$html];

	}


	private function curl_connect($host)
	{
		$ip = gethostbyname($host);

		$curl = new Curl();

		$cookie_file = tempnam(sys_get_temp_dir(), 'ArticleExtractor_');
		$curl->setCookieJar($cookie_file);
		$curl->setCookieFile($cookie_file);
		$curl->setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_2) AppleWebKit/601.3.9 (KHTML, like Gecko) Version/9.0.2 Safari/601.3.9');

		$curl->setOpt(CURLOPT_SSL_VERIFYHOST, false);
		$curl->setOpt(CURLOPT_SSL_VERIFYPEER, false);
		$curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
		$curl->setOpt(CURLOPT_RETURNTRANSFER, true);
		$curl->setOpt(CURLOPT_MAXREDIRS, 5);

		$curl->setOpt(CURLOPT_RESOLVE, array($host . ":80:" . $ip, $host . ":443:" . $ip));

		return $curl;
	}

	private function webpage_get_contents($url) {

		$parsed_url = parse_url($url);

		$curl = $this->curl_connect($parsed_url['host']);

		$error_counter = 0;

		do {

			$this->log_debug('Loading page: ' . $url);

			$curl->setReferrer($parsed_url['host']);
			$curl->get($url);

			if ($curl->error) {

				if ($curl->errorCode == 404) {

					$this->log_debug('Error: ' . $curl->errorCode . ': ' . $curl->errorMessage);

					$curl->close();

					return false;
				}
				else {

					$this->log_debug('Error: ' . $curl->errorCode . ': ' . $curl->errorMessage);

					$error_counter++;

					if ($error_counter == 3) {

						$this->log_debug('Error - stopping after ' . $error_counter . ' retries: ' . $curl->errorMessage);

						$curl->close();

						return false;

					}

					$curl->close();

					sleep(300);

					$curl = $this->curl_connect($parsed_url['host']);

					continue;

				}

			}

		} while (!$curl->response);

		$this->log_debug('Final Url: ' . $curl->getInfo(CURLINFO_EFFECTIVE_URL));

		return $curl;

	}

	private function checkMetaRefresh($url, $maximumRedirections = 5, $currentRedirection = 0)
	{
		$result = false;

		$contents = $this->webpage_get_contents($url);

		// Check if we need to go somewhere else

		if (isset($contents->response) && is_string($contents->response) && strlen($contents->response) < 500)
		{
			preg_match_all('/<[\s]*meta[\s]*http-equiv="?REFRESH"?' . '[\s]*content="?[0-9]*;[\s]*URL[\s]*=[\s]*([^>"]*)"?' . '[\s]*[\/]?[\s]*>/si', $contents->response, $match);

			if (isset($match) && is_array($match) && count($match) == 2 && count($match[1]) == 1)
			{
				if (!isset($maximumRedirections) || $currentRedirection < $maximumRedirections)
				{
					return $this->checkMetaRefresh($match[1][0], $maximumRedirections, ++$currentRedirection);
				}

				$result = false;
			}
			else
			{
				$result = $contents;
			}
		}

		return $contents;
	}


	/**
	 * Shifts encoding to UTF if needed
	 */
	private function shiftEncodingToUTF8($text) {

		if ($encoding = mb_detect_encoding($text, mb_detect_order(), true)) {
			$this->log_debug("shiftEncodingToUTF8 detected encoding of " . $encoding . " -> shifting to UTF-8");
			return iconv($encoding, "UTF-8", $text);
		}
		else {
			$this->log_debug("shiftEncodingToUTF8 detected NO encoding -> leaving as is");
			return $text;
		}
	}

	private function peerAnalysis($element) {

		$this->log_debug("PEER ANALYSIS ON " . $element->tag->name() . " (" . $element->getAttribute('class') . ")");

		$range = 0.50;

		$element_wc = str_word_count($element->text(true));
		$element_whitecount = substr_count($element->text(true), ' ');
		$element_wc_ratio = $element_whitecount / $element_wc;

		if ($element->getParent() != null) {

			$parent = $element->getParent();
			$this->log_debug("	Parent: " . $parent->tag->name() . " (" . $parent->getAttribute('class') . ")");

			$peers_with_close_wc = 0;

			foreach($parent->getChildren() as $child)
			{
				$child_wc = str_word_count($child->text(true));
				$child_whitecount = substr_count($child->text(true), ' ');

				if ($child_wc != 0) {
					$child_wc_ratio = $child_whitecount / $child_wc;

					$this->log_debug("	  Child: " . $child->tag->name() . " (" . $child->getAttribute('class') . ") WC: " . $child_wc . " Ratio: " . number_format($child_wc_ratio,2) );

					if ($child_wc > ($element_wc * $range) && $child_wc < ($element_wc * (1 + $range))) {
						$this->log_debug("** good peer found **");
						$peers_with_close_wc++;
					}
				}
			}

			if ($peers_with_close_wc > 2) {
				$this->log_debug("Returning parent");
				return $parent;
			}
			else {
				$this->log_debug("Not enough good peers, returning original element");
				return $element;
			}
		}
		else {
			$this->log_debug("Element has no parent - returning original element");
			return $element;
		}
	}

	private function buildAllNodeList($element, $depth = 0) {

		$return_array = array();

		// Debug what we are checking

		if($element->getTag()->name() != "text") {

			$this->log_debug("buildAllNodeList: " . str_repeat(' ', $depth*2) . $element->getTag()->name() . " ( " . $element->getAttribute('class') . " )");

			// Look at each child div element
			if ($element->hasChildren()) {

				foreach($element->getChildren() as $child)
				{
					// Push the children's children
					$return_array = array_merge($return_array, array_values($this->buildAllNodeList($child, $depth+1)));

					// Include the following tags in the counts for children and number of words
					if (in_array($child->tag->name(),$this->valid_root_elements)) {
						array_push($return_array, $child);
					}
				}
			}
		}
		else {
			$this->log_debug("buildAllNodeList: " . str_repeat(' ', $depth*2) . $element->getTag()->name());
		}
		return $return_array;
	}

	private function log_debug($message) {
		if ($this->debug) {
			echo $message . "\n";
		}
	}

	/*
	 * This function gets the text representation of a node and works recursively to do so.
	 * It also trys to format an extra space in HTML elements that create concatenation
	 * issues when they are slapped together
	 */
	private function getTextForNode($element) {

		$text = '';

		$this->log_debug("getTextForNode: "	 . $element->getTag()->name());

		// Look at each child
		foreach ($element->getChildren() as $child) {

			// If its a text node, just give it the nodes text
			if ($child instanceof TextNode) {
				$text .= $child->text();
			}
			// Otherwise, if it is an HtmlNode
			elseif ($child instanceof HtmlNode) {

				// If this is one of the HTML tags we want to add a space to
				if (in_array($child->getTag()->name(),$this->space_elements)) {
					$text .= " " . $this->getTextForNode($child);
				}
				else {
					$text .= $this->getTextForNode($child);
				}
			}
		}

		// Return our text string
		return $text;
	}


	/**
	 * Checks the passed in HTML for any hints within the HTML for language. Should
	 * return the ISO 639-1 language code if found or false if no language could be determined
	 * from the dom model.
	 *
	 */
	private function checkHTMLForLanguageHint($html_string) {

    try {
      // Ok then try it a different way
  		$dom = new Dom;
  		$dom->load($html_string, ['whitespaceTextNode' => false]);

  		$htmltag = $dom->find('html');
  		$lang = $htmltag->getAttribute('lang');

  		// Check for lang in HTML tag
  		if ($lang != null) {
  			$this->log_debug("checkHTMLForLanguageHint: Found language: " . $lang . ", returning " . substr($lang,0,2));
  			return substr($lang,0,2);
  		}
  		// Otherwise...
  		else {

  			// Check to see if we have a <meta name="content-language" content="ja" /> type tag
  			$metatags = $dom->find("meta");

  			foreach ($metatags as $tag) {
  				$this->log_debug("Checking tag: " . $tag->getAttribute('name'));
  				if ($tag->getAttribute('name') == 'content-language') {
  					return $tag->getAttribute('content');
  				}
  			}

  			$this->log_debug("checkHTMLForLanguageHint: Found no language");
  			return false;
  		}
    }
    catch (\Exception $e) {
      $this->log_debug("checkHTMLForLanguageHint: Returning false as exception occurred: " . $e->getMessage());
      return false;
    }

	}

	/**
	function translateText($text, $targetLang)
	{
		$baseUrl = "https://translate.yandex.net/api/v1.5/tr.json/translate?key=YOUR_yandex_api_key";
		$url = $baseUrl . "&text=" . urlencode($text) . "&lang=" . urlencode($targetLang);

		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_CAINFO, YOUR_CERT_PEM_FILE_LOCATION);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		$output = curl_exec($ch);
		if ($output)
		{
			$outputJson = json_decode($output);
			if ($outputJson->code == 200)
			{
				if (count($outputJson->text) > 0 && strlen($outputJson->text[0]) > 0)
				{
					return $outputJson->text[0];
				}
			}
		}

		return $text;
	}
	*/
}

?>
