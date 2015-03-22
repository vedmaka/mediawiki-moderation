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
	@brief Verifies that modaction=merge works as expected.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationActionMerge
*/
class ModerationTestMerge extends MediaWikiTestCase
{
	public function testMerge() {
		$t = new ModerationTestsuite();

		/*
			This is how we create edit conflict:
			1) The page has 4 lines of text,
			2) User A deletes 2 lines,
			3) User B modifies one of those deleted lines.

			Both users edit the original revision of the page.
		*/

		$page = 'Test page 1';
		$text0 = "Normal line 1\nNot very interesting line 2\n" .
			"Not very interesting line 3\nNormal line 4\n";
		$text1 = "Normal line 1\nJust made line 2 more interesting\n" .
			"Not very interesting line 3\nNormal line 4\n";
		$text2 = "Normal line 1\nNormal line 4\n";

		$t->loginAs($t->automoderated);
		$t->doTestEdit($page, $text0, "Create an article. Some lines here are boring.");

		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit($page, $text1, "Improve one of the boring lines");
		$t->fetchSpecial();

		$t->loginAs($t->automoderated);
		$t->doTestEdit($page, $text2, "Delete all boring lines");

		$t->loginAs($t->moderator);
		$error = $t->html->getModerationError($t->new_entries[0]->approveLink);
		$this->assertEquals('(moderation-edit-conflict)', $error,
			"testMerge(): Edit conflict not detected by modaction=approve");

		$t->fetchSpecial();

		$this->assertCount(0, $t->new_entries,
			"testMerge(): Something was added into Pending folder when modaction=approve detected edit conflict");
		$this->assertCount(0, $t->deleted_entries,
			"testMerge(): Something was deleted from Rejected folder when modaction=approve detected edit conflict");

		$t->assumeFolderIsEmpty();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$this->assertNotNull($entry->mergeLink,
			"testMerge(): Merge link not found for edit with detected conflict");

		$this->assertNotNull($entry->rejectLink,
			"testMerge(): Reject link not found for edit with detected conflict");
		$this->assertNotNull($entry->rejectAllLink,
			"testMerge(): RejectAll link not found for edit with detected conflict");
		$this->assertNotNull($entry->showLink,
			"testMerge(): Show link not found for edit with detected conflict");
		$this->assertNotNull($entry->blockLink,
			"testMerge(): Block link not found for edit with detected conflict");

		$this->assertNull($entry->approveLink,
			"testMerge(): Approve link found for edit with detected conflict");
		$this->assertNull($entry->approveAllLink,
			"testMerge(): ApproveAll link found for edit with detected conflict");

		$this->assertNull($entry->rejected_by_user,
			"testMerge(): Not yet rejected edit with detected conflict is marked rejected");
		$this->assertFalse($entry->rejected_batch,
			"testMerge(): Not yet rejected edit with detected conflict has rejected_batch flag ON");
		$this->assertFalse($entry->rejected_auto,
			"testMerge(): Not yet rejected edit with detected conflict has rejected_auto flag ON");
	}
}
