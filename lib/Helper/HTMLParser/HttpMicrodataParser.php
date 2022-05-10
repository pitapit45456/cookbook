<?php

namespace OCA\Cookbook\Helper\HTMLParser;

use OCA\Cookbook\Exception\HtmlParsingException;
use OCP\IL10N;

/**
 * This class is an AbsractHtmlParser that tries to extract microdata from the HTML page.
 * @author Christian Wolf
 * @todo Nutrition data is missing
 * @todo Category needs checking
 * @todo Tools need to be imported
 */
class HttpMicrodataParser extends AbstractHtmlParser {
	
	/**
	 * @var \DOMXPath
	 */
	private $xpath;
	
	/**
	 * @var array
	 */
	private $recipe;
	
	public function __construct(IL10N $l10n) {
		parent::__construct($l10n);
	}
	
	public function parse(\DOMDocument $document): array {
		$this->xpath = new \DOMXPath($document);
		
		$selectorHttp = "*[@itemtype='http://schema.org/Recipe']";
		$selectorHttps = "*[@itemtype='https://schema.org/Recipe']";
		$xpathSelector = "$selectorHttp | $selectorHttps";
		
		$recipes = $this->xpath->query($xpathSelector);
		
		if (count($recipes) === 0) {
			throw new HtmlParsingException($this->l->t('No recipe was found.'));
		}
		
		$this->recipe = [
			'@type' => 'Recipe',
			'@context' => 'http://schema.org'
		];
		$this->parseRecipe($recipes[0]);
		
		return $this->recipe;
	}

	/**
	 * Parse a DOM node that represents a recipe
	 *
	 * @param \DOMNode $recipeNode The DOM node to parse
	 */
	private function parseRecipe(\DOMNode $recipeNode) {
		$this->seachSimpleProperties($recipeNode, 'name');
		$this->seachSimpleProperties($recipeNode, 'keywords');
		$this->seachSimpleProperties($recipeNode, 'category');
		$this->seachSimpleProperties($recipeNode, 'recipeYield');
		
		$this->parseImage($recipeNode);
		$this->parseIngredients($recipeNode);
		$this->parseInstructions($recipeNode);
		
		$this->fixupInstructions($recipeNode);
	}
	
	/**
	 * Make one final desparate attempt at getting the instructions
	 * @param \DOMNode $recipeNode The recipe node to use
	 */
	private function fixupInstructions(\DOMNode $recipeNode) {
		if (!isset($this->recipe['recipeInstructions']) ||
			!$this->recipe['recipeInstructions'] || sizeof($this->recipe['recipeInstructions']) < 1) {
			$this->recipe['recipeInstructions'] = [];
			
			$step_elements = $recipeNode->getElementsByTagName('p');
			
			foreach ($step_elements as $step_element) {
				if (!$step_element || !$step_element->nodeValue) {
					continue;
				}
				
				$this->recipe['recipeInstructions'][] = $step_element->nodeValue;
			}
		}
	}
	
	/**
	 * Search for images in the microdata of a recipe
	 * @param \DOMNode $recipeNode The recipe to search within
	 * @return boolean true, if a property was found
	 */
	private function parseImage(\DOMNode $recipeNode) {
		return $this->searchMultipleProperties($recipeNode,
			['image', 'images', 'thumbnail'],
			['src', 'content'],
			'image');
	}
	
	/**
	 * Search for ingredients in the microdata of a recipe
	 * @param \DOMNode $recipeNode The recipe to search within
	 * @return boolean true, if a property was found
	 */
	private function parseIngredients(\DOMNode $recipeNode) {
		return $this->searchMultipleProperties($recipeNode,
			['recipeIngredient', 'ingredients'],
			['content'],
			'recipeIngredient');
	}
	
	/**
	 * Search for instructions in the microdata of a recipe
	 * @param \DOMNode $recipeNode The recipe to search within
	 * @return boolean true, if a property was found
	 */
	private function parseInstructions(\DOMNode $recipeNode) {
		return $this->searchMultipleProperties($recipeNode,
			['content'],
			['recipeInstructions', 'instructions', 'steps', 'guide'],
			'recipeInstructions');
	}
	
	/**
	 * Search for microdata properties under various names in the recipe and save as a list
	 *
	 * If a property can be named differntly and found under differnt property names within
	 * the DOM tree, this method looks for all these options.
	 * It is similar to the searchSimpleProperties() method but allows to search for different
	 * names within the DOM tree. This can be useful when a property is superseeded and the code
	 * should still be backward compatible.
	 *
	 * @param \DOMNode $recipeNode The node of the recipe to look for properties in
	 * @param array $properties The properties to look for one-by-one
	 * @param array $attributes The attributes that will contain the data
	 * @param string $dst The name of the property list in the internal structure
	 * @return boolean true, if the property was found
	 */
	private function searchMultipleProperties(\DOMNode $recipeNode, array $properties,
		array $attributes, string $dst) {
		$arrayObtained = [];
		
		foreach ($properties as $prop) {
			$entries = $this->searchChildEntries($recipeNode, $prop);
			
			try {
				$arrayObtained[] = $this->extractAttribute($entries, $attributes);
			} catch (AttributeNotFoundException $ex) {
				// Test with the next property name
				continue;
			}
		}
		
		if (count($arrayObtained) > 0) {
			$this->recipe[$dst] = $arrayObtained;
			return true;
		}
		
		return false;
	}
	
	/**
	 * Search for microdata properties of the recipe.
	 *
	 * Within the recipe node search for DOM entries that represent a property and assign the
	 * internal structure to their value.
	 *
	 * @param \DOMNode $recipeNode The node of the recipe under which to look for properties
	 * @param string $property The property name to look for
	 * @return bool true, if the property was found
	 */
	private function seachSimpleProperties(\DOMNode $recipeNode, string $property): bool {
		$entries = $this->searchChildEntries($recipeNode, $property);
		
		try {
			$value = $this->extractAttribute($entries, ['content']);
		} catch (AttributeNotFoundException $ex) {
			return false;
		}
		
		$this->recipe[$property] = $value;
		return true;
	}
	
	/**
	 * Search for child entries that are representing a certain property
	 *
	 * @param \DOMNode $recipeNode The root node to search in
	 * @param string $prop The name of the property to look for
	 * @return \DOMNodeList A list of all found child nodes with the given property
	 */
	private function searchChildEntries(\DOMNode $recipeNode, string $prop): \DOMNodeList {
		return $this->xpath->query("//*[@itemprop='$prop']", $recipeNode);
	}
	
	/**
	 * Extract the value from a HTML attribute and set the correspondig value in the internal recipe cache.
	 *
	 * This method checks a set of notes if any of these nodes contain an attribute that can be used
	 * to extract some microdata. The nodes are iterated one-by-one. As soon as a match is found,
	 * the method saved the corresponding value and terminates.
	 *
	 * In each node each attribute is checked (in order of occurence) if the node has such an attribute.
	 * If it has such an attribute that attribute is assumed to be the searched value and is used.
	 * If no attribute is found, the content of the node is checked and if it is non-empty, the content
	 * of the node is used instead.
	 *
	 * If a value is found, it is stored in the internal structure under the key given by $dst.
	 *
	 * @param \DOMNodeList $nodes The list of all nodes to look for corresponding attributes
	 * @param array $attributes The attributes to check
	 * @param string $dst The name of the property in the internal recipe object
	 * @return bool true, if an attribute has been found and saved
	 * @deprecated Do not use as overridden with better structure
	 */
	private function extractAndSaveAttribute(\DOMNodeList $nodes, array $attributes,
		string $dst): bool {
		/** @var $node \DOMElement */
		foreach ($nodes as $node) {
			foreach ($attributes as $attr) {
				if ($node->hasAttribute($attr) && !empty($node->getAttribute($attr))) {
					$this->recipe[$dst] = $node->getAttribute($attr);
					return true;
				}
			}
			
			if (!empty(trim($node->textContent))) {
				$this->recipe[$dst] = trim($node->textContent);
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Extract the value from a HTML attribute
	 *
	 * This method checks a set of notes if any of these nodes contain an attribute that can be used
	 * to extract some microdata. The nodes are iterated one-by-one. As soon as a match is found,
	 * the method retunes the corresponding value and terminates.
	 *
	 * In each node each attribute is checked (in order of occurence) if the node has such an attribute.
	 * If it has such an attribute that attribute is assumed to be the searched value and is used.
	 * If no attribute is found, the content of the node is checked and if it is non-empty, the content
	 * of the node is used instead.
	 *
	 * If no node contains an attribute with one of the given names and no content, an exception is
	 * thrown.
	 *
	 * @param \DOMNodeList $nodes The list of all nodes to look for corresponding attributes
	 * @param array $attributes The attributes to check
	 * @throws AttributeNotFoundException If the property was not found in any node
	 * @return string The value of the property found
	 */
	private function extractAttribute(\DOMNodeList $nodes, array $attributes): string {
		/** @var $node \DOMElement */
		foreach ($nodes as $node) {
			foreach ($attributes as $attr) {
				if ($node->hasAttribute($attr) && !empty($node->getAttribute($attr))) {
					return $node->getAttribute($attr);
				}
			}
			
			if (!empty(trim($node->textContent))) {
				return trim($node->textContent);
			}
		}
		
		throw new AttributeNotFoundException();
	}
}
