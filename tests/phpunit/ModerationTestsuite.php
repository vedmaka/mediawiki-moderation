<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
	@file
	@brief Automated testsuite of Extension:Moderation.
*/

class ModerationTestsuite
{
	function __construct() {
		$this->PrepareAPIForTests();
		$this->PrepareDbForTests();
	}

	#
	# Part 1. Api-related methods.
	#
	private $apiUrl;
	private $editToken = false;
	private $cookie_jar; # Cookie storage (from login() and anonymous preloading)

	private $TEST_PASSWORD = '123456';

	private function PrepareAPIForTests()
	{
		global $wgServer, $wgScriptPath;

		$this->apiUrl = wfExpandUrl($wgServer, PROTO_HTTP) . "/$wgScriptPath/api.php";
		$this->cookie_jar = new CookieJar;
		$this->getEditToken();
	}
	private function getEditToken() {
		$ret = $this->query(array(
			'action' => 'tokens',
			'type' => 'edit'
		));

		$this->editToken = $ret['tokens']['edittoken'];
	}

	public function makeHttpRequest($url, $method = 'POST')
	{
		$req = MWHttpRequest::factory($url, array('method' => $method));
		$req->setCookieJar($this->cookie_jar);

		return $req;
	}

	public function query($query = array()) {
		$query['format'] = 'json';

		if(array_key_exists('token', $query))
			$query['token'] = $this->editToken;

		$url = wfAppendQuery($this->apiUrl, $query);

		$req = $this->makeHttpRequest($url);
		$status = $req->execute();

		if(!$status->isOK())
			return false;

		return FormatJson::decode($req->getContent(), true);
	}

	public function login($username) {
		# Step 1. Get the token.
		$ret = $this->query(array(
			'action' => 'login',
			'lgname' => $username,
			'lgpassword' => $this->TEST_PASSWORD
		));
		$token = $ret['login']['token'];

		# Step 2. Actual login.
		$ret = $this->query(array(
			'action' => 'login',
			'lgname' => $username,
			'lgpassword' => $this->TEST_PASSWORD,
			'lgtoken' => $token
		));

		if($ret['login']['result'] == 'Success') {
			$this->getEditToken(); # It's different for a logged-in user
			return true;
		}

		return false;
	}

	public function logout() {
		$this->cookie_jar = new CookieJar; # Just delete all cookies
		$this->getEditToken();
	}

	#
	# Part 2. Functions for parsing Special:Moderation.
	#
	private $lastFetchedSpecial = array();

	public $new_entries;
	public $deleted_entries;

	public function fetchSpecial($folder = 'DEFAULT')
	{
		global $wgServer, $wgScriptPath;

		# When "uselang=qqx" is specified, messages are replaced with
		# their names, so parsing process is translation-independent.
		# (see ModerationTestsuiteEntry::fromDOMElement)

		$url = wfExpandUrl($wgServer, PROTO_HTTP) . $wgScriptPath .
			'/index.php?title=Special:Moderation&limit=150&uselang=qqx';
		if($folder != 'DEFAULT')
			$url .= '&folder=' . $folder;

		$this->loginAs($this->moderator);

		$req = $this->makeHttpRequest($url, 'GET');
		$status = $req->execute();
		if(!$status->isOK())
			return false;

		$text = $req->getContent();
		$entries = array();

		$html = DOMDocument::loadHTML($text);
		$spans = $html->getElementsByTagName('span');

		foreach($spans as $span)
		{
			if($span->getAttribute('class') == 'modline')
				$entries[] = ModerationTestsuiteEntry::fromDOMElement($span);
		}

		$this->lastFetchedSpecial[$folder] = $entries;
		return $entries;
	}
	public function fetchSpecialAndDiff($folder = 'DEFAULT')
	{
		$before = $this->lastFetchedSpecial[$folder];
		$after = $this->fetchSpecial($folder);

		$this->new_entries = ModerationTestsuiteEntry::entriesInANotInB($after, $before);
		$this->deleted_entries = ModerationTestsuiteEntry::entriesInANotInB($before, $after);
	}

	#
	# Part 3. Database-related functions.
	#
	private function createTestUser($name, $groups = array())
	{
		$user = User::createNew($name);
		$user->setPassword($this->TEST_PASSWORD);
		$user->saveSettings();

		foreach($groups as $g)
			$user->addGroup($g);

		return $user;
	}
	private function PrepareDbForTests()
	{
		/* Controlled environment
			as in "Destroy everything on testsuite's path" */

		$dbw = wfGetDB(DB_MASTER);
		$dbw->begin();
		$dbw->delete('moderation', array('1'), __METHOD__);
		$dbw->delete('moderation_block', array('1'), __METHOD__);
		$dbw->delete('user', array('1'), __METHOD__);
		$dbw->delete('user_groups', array('1'), __METHOD__);
		$dbw->delete('page', array('1'), __METHOD__);
		$dbw->delete('revision', array('1'), __METHOD__);
		$dbw->delete('logging', array('1'), __METHOD__);
		$dbw->delete('text', array('1'), __METHOD__);

		$this->moderator =
			$this->createTestUser('User 1', array('moderator', 'automoderated'));
		$this->moderatorButNotAutomoderated =
			$this->createTestUser('User 2', array('moderator'));
		$this->automoderated =
			$this->createTestUser('User 3', array('automoderated'));
		$this->rollback =
			$this->createTestUser('User 4', array('rollback'));
		$this->unprivilegedUser =
			$this->createTestUser('User 5', array());

		$dbw->commit();
	}

	#
	# Part 4. High-level test functions.
	#
	public $moderator;
	public $moderatorButNotAutomoderated;
	public $rollback;
	public $automoderated;
	public $unprivilegedUser;

	private $t_loggedInAs;

	public function loggedInAs()
	{
		return $this->t_loggedInAs;
	}

	public function loginAs($user)
	{
		$this->login($user->getName());
		$this->t_loggedInAs = $user;
	}

	public function doTestEdit()
	{
		$title = $this->generateRandomTitle();
		$text = $this->generateRandomText();
		$summary = $this->generateEditSummary();

		# TODO: ensure that page $title doesn't already contain $text
		# (to avoid extremely rare test failures due to random collisions)

		$res = $this->query(array(
			'action' => 'edit',
			'title' => $title,
			'text' => $text,
			'summary' => $summary,
			'token' => null
		));

		/* TODO: check if successful */

		$this->lastEdit = array();
		$this->lastEdit['User'] = $this->loggedInAs()->getName();
		$this->lastEdit['Title'] = $title;
		$this->lastEdit['Text'] = $text;
		$this->lastEdit['Summary'] = $summary;
	}
	function generateRandomTitle()
	{
		/* Simple string, no underscores */

		return "Test page 1"; /* TODO: randomize */
	}

	function generateRandomText()
	{
		return "Hello, World!"; /* TODO: randomize */
	}

	function generateEditSummary()
	{
		/*
			NOTE: No wikitext! Plaintext only.
			Otherwise we'll have to run it through the parser before
			comparing to what's shown on Special:Moderation.
		*/

		return "Edit by the Moderation Testsuite";
	}
}

/**
	@class ModerationTestsuiteEntry
	@brief Represents one line on [[Special:Moderation]]
*/
class ModerationTestsuiteEntry
{
	public $id;
	public $user;
	public $comment; /* TODO */
	public $title;

	public $showLink;
	public $approveLink;
	public $approveAllLink;
	public $rejectLink;
	public $rejectAllLink;
	public $blockLink;
	public $unblockLink;

	static public function fromDOMElement($span)
	{
		$e = new ModerationTestsuiteEntry;

		$links = $span->getElementsByTagName('a');
		foreach($links as $link)
		{
			if(strpos($link->getAttribute('class'), 'mw-userlink') != false)
			{
				$e->user = $link->textContent;
				continue;
			}

			$href = $link->getAttribute('href');
			switch($link->nodeValue)
			{
				case '(moderation-show)':
					$e->showLink = $href;
					break;

				case '(moderation-approve)':
					$e->approveLink = $href;
					break;

				case '(moderation-approveall)':
					$e->approveAllLink = $href;
					break;

				case '(moderation-reject)':
					$e->rejectLink = $href;
					break;

				case '(moderation-rejectall)':
					$e->rejectAllLink = $href;
					break;

				case '(moderation-block)':
					$e->blockLink = $href;
					break;

				case '(moderation-unblock)':
					$e->blockLink = $href;
					break;

				default:
					$e->title = $link->textContent;
			}
		}

		$matches = null;
		preg_match('/modid=([0-9]+)/', $e->showLink, $matches);
		$e->id = $matches[1];

		return $e;
	}

	static public function entriesInANotInB($array_A, $array_B)
	{
		# NOTE: the code below doesn't handle the situation when there
		# are 150 entries, 1 new entry is added and one existing entry
		# is moved to another page.

		$diff = array();
		foreach($array_A as $e)
		{
			$found = 0;
			foreach($array_B as $b)
			{
				if($e->id == $b->id)
				{
					$found = 1;
					break;
				}
			}

			if(!$found)
				$diff[] = $e;
		}
		return $diff;
	}
}