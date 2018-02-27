<?php

namespace nglasl\extensible;

use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\ValidationException;

/**
 *	Handles the search analytics and suggestions, while providing any additional functionality required by the module.
 *	@author Nathan Glasl <nathan@symbiote.com.au>
 */

class ExtensibleSearchService {

	/**
	 *	Log the details of a user search for analytics.
	 *
	 *	@parameter <{SEARCH_TERM}> string
	 *	@parameter <{NUMBER_OF_SEARCH_RESULTS}> integer
	 *	@parameter <{SEARCH_TIME}> float
	 *	@parameter <{SEARCH_ENGINE}> string
	 *	@parameter <{EXTENSIBLE_SEARCH_PAGE_ID}> integer
	 *	@return extensible search
	 */

	public function logSearch($term, $results, $time, $engine, $pageID) {

		// Make sure the search analytics are enabled.

		if(!Config::inst()->get(ExtensibleSearch::class, 'enable_analytics')) {
			return null;
		}

		// Log the details of the user search.

		$search = ExtensibleSearch::create(
			array(
				'Term'	=> $term,
				'Results' => $results,
				'Time' => $time,
				'SearchEngine' => $engine,
				'ExtensibleSearchPageID' => $pageID
			)
		);
		$search->write();

		// Log the details of the user search as a suggestion.

		if($results > 0) {
			$this->logSuggestion($term, $pageID);
		}
		return $search;
	}

	/**
	 *	Log a user search generated suggestion.
	 *
	 *	@parameter <{SEARCH_TERM}> string
	 *	@parameter <{EXTENSIBLE_SEARCH_PAGE_ID}> integer
	 *	@return extensible search suggestion
	 */

	public function logSuggestion($term, $pageID) {

		// Make sure the search matches the minimum autocomplete length.

		if(strlen($term) < 3) {
			return null;
		}

		// Make sure the suggestion doesn't already exist.

		$term = strtolower($term);
		$filter = array(
			'Term' => $term,
			'ExtensibleSearchPageID' => $pageID
		);
		$suggestion = ExtensibleSearchSuggestion::get()->filter($filter)->first();

		// Store the frequency to make search suggestion relevance more efficient.

		$frequency = ExtensibleSearch::get()->filter($filter)->count();
		if($suggestion) {
			$suggestion->Frequency = $frequency;
		}
		else {

			// Log the suggestion.

			$suggestion = ExtensibleSearchSuggestion::create(
				array(
					'Term' => $term,
					'Frequency' => $frequency,
					'Approved' => (int)Config::inst()->get(ExtensibleSearchSuggestion::class, 'automatic_approval'),
					'ExtensibleSearchPageID' => $pageID
				)
			);
		}

		// The suggestion might now exist.

		try {
			$suggestion->write();
		}
		catch(ValidationException $exception) {

			// This indicates a possible race condition.

			$suggestions = ExtensibleSearchSuggestion::get()->filter($filter);
			while($suggestions->count() > 1) {
				$suggestions->last()->delete();
			}
			$suggestion = $suggestions->first();
			$frequency = ExtensibleSearch::get()->filter($filter)->count();
			$suggestion->Frequency = $frequency;
			$suggestion->write();
		}
		return $suggestion;
	}

	/**
	 *	Toggle a search suggestion's approval.
	 *
	 *	@parameter <{SUGGESTION_ID}> integer
	 *	@return string
	 */

	public function toggleSuggestionApproved($ID) {

		if($suggestion = ExtensibleSearchSuggestion::get()->byID($ID)) {

			// Update the search suggestion.

			$approved = !$suggestion->Approved;
			$suggestion->Approved = $approved;
			$suggestion->write();

			// Determine the approval status.

			$status = $approved ? 'Approved' : 'Disapproved';
			return "{$status} \"{$suggestion->Term}\"!";
		}
		else {
			return null;
		}
	}

	/**
	 *	Retrieve the most relevant search suggestions.
	 *
	 *	@parameter <{SEARCH_TERM}> string
	 *	@parameter <{EXTENSIBLE_SEARCH_PAGE_ID}> integer
	 *	@parameter <{LIMIT}> integer
	 *	@parameter <{APPROVED_ONLY}> boolean
	 *	@return array
	 */

	public function getSuggestions($term, $pageID, $limit = 5, $approved = true) {

		// Make sure the search matches the minimum autocomplete length.

		if($term && (strlen($term) > 2)) {

			// Make sure the current user has appropriate permission.

			$pageID = (int)$pageID;
			if(($page = ExtensibleSearchPage::get_by_id(ExtensibleSearchPage::class, $pageID)) && $page->canView()) {

				// Retrieve the search suggestions.

				$suggestions = ExtensibleSearchSuggestion::get()->filter(array(
					'Term:StartsWith' => $term,
					'Approved' => (int)$approved,
					'ExtensibleSearchPageID' => $pageID
				))->sort('Frequency', 'DESC')->limit($limit);

				// Make sure the search suggestions are unique.

				return array_unique($suggestions->column('Term'));
			}
		}
		return array();
	}

	/**
	 *	Retrieve the search suggestions for a page.
	 *
	 *	@parameter <{EXTENSIBLE_SEARCH_PAGE_ID}> integer
	 *	@parameter <{APPROVED_ONLY}> boolean
	 *	@return array
	 */

	public function getPageSuggestions($pageID, $limit = 0, $approved = true) {

		// Make sure the current user has appropriate permission.

		$pageID = (int)$pageID;
		if(($page = ExtensibleSearchPage::get_by_id(ExtensibleSearchPage::class, $pageID)) && $page->canView()) {

			// Retrieve the search suggestions.

			$suggestions = ExtensibleSearchSuggestion::get()->filter(array(
				'Approved' => (int)$approved,
				'ExtensibleSearchPageID' => $pageID
			))->sort('Frequency', 'DESC');
			if($limit) {
				$suggestions = $suggestions->limit($limit);
			}

			// Make sure the search suggestions are unique.

			return array_unique($suggestions->column('Term'));
		}
		return array();
	}

}
