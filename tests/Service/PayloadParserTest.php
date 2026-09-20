<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ZammadBot\Tests\Service;

use OCA\ZammadBot\Exception\InvalidPayloadException;
use OCA\ZammadBot\Service\PayloadParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

class PayloadParserTest extends TestCase {
	protected PayloadParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new PayloadParser();
	}

	public function testDefaultPayload(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => [
				'id' => 42,
				'number' => '1234',
				'title' => 'Printer on fire',
				'tags' => ['team-infra', 'urgent'],
				'state' => 'open',
				'priority' => '2 normal',
				'group' => 'Users',
				'customer' => ['fullname' => 'Ada Lovelace', 'email' => 'ada@example.com'],
			],
			'article' => ['subject' => 'ignored'],
		], JSON_THROW_ON_ERROR));

		$this->assertSame(42, $ticket->id);
		$this->assertSame('1234', $ticket->number);
		$this->assertSame('Printer on fire', $ticket->title);
		$this->assertSame(['team-infra', 'urgent'], $ticket->tags);
		$this->assertSame('open', $ticket->state);
		$this->assertSame('2 normal', $ticket->priority);
		$this->assertSame('Users', $ticket->group);
		$this->assertSame('Ada Lovelace', $ticket->customer);
	}

	/**
	 * A custom payload delivers every value as a string.
	 */
	public function testCustomPayloadWithStringValues(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => [
				'id' => '4711',
				'number' => '1234',
				'title' => 'Disk full',
				'tags' => 'team-infra, urgent',
				'customer' => 'Ada Lovelace',
			],
		], JSON_THROW_ON_ERROR));

		$this->assertSame(4711, $ticket->id);
		$this->assertSame(['team-infra', 'urgent'], $ticket->tags);
		$this->assertSame('Ada Lovelace', $ticket->customer);
	}

	public static function dataTags(): array {
		return [
			'array' => [['ticket' => ['id' => 1, 'tags' => ['a', 'b']]], ['a', 'b']],
			'comma separated' => [['ticket' => ['id' => 1, 'tags' => 'a, b']], ['a', 'b']],
			'rendered array' => [['ticket' => ['id' => 1, 'tags' => '["a", "b"]']], ['a', 'b']],
			'tag_list key' => [['ticket' => ['id' => 1, 'tag_list' => ['a']]], ['a']],
			'top level tags' => [['ticket' => ['id' => 1], 'tags' => ['a']], ['a']],
			'top level single tag' => [['ticket' => ['id' => 1], 'tag' => 'team-infra'], ['team-infra']],
			'absent' => [['ticket' => ['id' => 1]], []],
			'normalised' => [['ticket' => ['id' => 1, 'tags' => [' Team-Infra ', 'TEAM-INFRA']]], ['team-infra']],
			'empty entries dropped' => [['ticket' => ['id' => 1, 'tags' => ['a', '', '  ']]], ['a']],
		];
	}

	#[DataProvider('dataTags')]
	public function testTagShapes(array $payload, array $expected): void {
		$ticket = $this->parser->parse(json_encode($payload, JSON_THROW_ON_ERROR));
		$this->assertSame($expected, $ticket->tags);
	}

	/**
	 * Zammad's real default payload: users carry firstname/lastname and a login
	 * that happens to be an email address, but no composed name.
	 */
	public function testDefaultPayloadUserIsComposedFromFirstAndLastName(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => [
				'id' => 105825,
				'customer' => [
					'firstname' => 'Ada',
					'lastname' => 'Lovelace',
					'login' => 'ada@example.com',
					'email' => 'ada@example.com',
				],
			],
		], JSON_THROW_ON_ERROR));
		$this->assertSame('Ada Lovelace', $ticket->customer);
	}

	public function testUserWithOnlyAFirstName(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => ['id' => 1, 'customer' => ['firstname' => 'Ada', 'login' => 'ada@example.com']],
		], JSON_THROW_ON_ERROR));
		$this->assertSame('Ada', $ticket->customer);
	}

	/**
	 * The default payload has no tags at all, which is why a custom payload is
	 * required for the bot to have anything to match on.
	 */
	public function testDefaultPayloadCarriesNoTags(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => [
				'id' => 105825,
				'number' => '96105572',
				'title' => 'test',
				'state' => 'open',
				'priority' => ['name' => '2 normal'],
				'group' => ['name' => 'Support'],
			],
			'article' => ['subject' => 'test'],
		], JSON_THROW_ON_ERROR));

		$this->assertSame([], $ticket->tags);
		$this->assertSame('2 normal', $ticket->priority);
		$this->assertSame('Support', $ticket->group);
		$this->assertSame('open', $ticket->state);
	}

	/**
	 * The single-webhook setup: one custom payload for every team, with the tag
	 * list rendered by Zammad's #{ticket.tag_list} variable. Zammad may render
	 * the array in more than one way, all of which must route correctly.
	 */
	#[DataProvider('dataRenderedTagList')]
	public function testRenderedTagListShapes(string $rendered, array $expected): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => ['id' => 105825, 'title' => 'test', 'tags' => $rendered],
		], JSON_THROW_ON_ERROR));
		$this->assertSame($expected, $ticket->tags);
	}

	public static function dataRenderedTagList(): array {
		return [
			'ruby array to_s' => ['["team-talk", "urgent"]', ['team-talk', 'urgent']],
			'json array no spaces' => ['["team-talk","urgent"]', ['team-talk', 'urgent']],
			'comma separated' => ['team-talk, urgent', ['team-talk', 'urgent']],
			'single tag' => ['team-talk', ['team-talk']],
			'one element array' => ['["team-talk"]', ['team-talk']],
			'empty array' => ['[]', []],
		];
	}

	/**
	 * An unresolved Zammad variable is never a tag, but it is kept aside so the
	 * response can point at the misconfiguration.
	 */
	public function testUnresolvedVariableIsReportedButNeverATag(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => ['id' => 1, 'tags' => '#{ticket.tag_list / no such method}'],
		], JSON_THROW_ON_ERROR));
		$this->assertSame([], $ticket->tags);
		$this->assertSame(['#{ticket.tag_list / no such method}'], $ticket->unresolved);
	}

	/**
	 * A webhook that still carries a leftover unresolvable `tags` variable must
	 * not shadow the literal `tag` the trigger sends.
	 */
	public function testLiteralTagWinsOverAnUnresolvableTagsVariable(): void {
		$ticket = $this->parser->parse(json_encode([
			'tag' => 'talk',
			'ticket' => [
				'id' => 105825,
				'number' => '96105572',
				'title' => 'test',
				'tags' => '#{ticket.tag_list / no such method}',
			],
		], JSON_THROW_ON_ERROR));

		$this->assertSame(['talk'], $ticket->tags);
		$this->assertSame(['#{ticket.tag_list / no such method}'], $ticket->unresolved);
	}

	public function testAnEmptyEarlierSourceDoesNotShadowALaterOne(): void {
		$ticket = $this->parser->parse(json_encode([
			'tag' => 'talk',
			'ticket' => ['id' => 1, 'tags' => []],
		], JSON_THROW_ON_ERROR));
		$this->assertSame(['talk'], $ticket->tags);
	}

	public function testFirstUsableSourceWins(): void {
		$ticket = $this->parser->parse(json_encode([
			'tag' => 'talk',
			'ticket' => ['id' => 1, 'tags' => ['team-infra']],
		], JSON_THROW_ON_ERROR));
		$this->assertSame(['team-infra'], $ticket->tags);
	}

	public function testCustomerObjectWithoutFullname(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => ['id' => 1, 'customer' => ['email' => 'ada@example.com']],
		], JSON_THROW_ON_ERROR));
		$this->assertSame('ada@example.com', $ticket->customer);
	}

	public function testMissingOptionalFields(): void {
		$ticket = $this->parser->parse('{"ticket":{"id":7}}');
		$this->assertSame(7, $ticket->id);
		$this->assertSame('', $ticket->number);
		$this->assertSame('', $ticket->title);
		$this->assertSame('', $ticket->state);
		$this->assertSame([], $ticket->tags);
	}

	public static function dataInvalid(): array {
		return [
			'not json' => ['not json at all'],
			'json array' => ['[]'],
			'json string' => ['"hello"'],
			'no ticket' => ['{"article":{}}'],
			'ticket not an object' => ['{"ticket":"x"}'],
			'no id' => ['{"ticket":{"title":"x"}}'],
			'zero id' => ['{"ticket":{"id":0}}'],
			'non numeric id' => ['{"ticket":{"id":"abc"}}'],
		];
	}

	#[DataProvider('dataInvalid')]
	public function testInvalidPayloads(string $body): void {
		$this->expectException(InvalidPayloadException::class);
		$this->parser->parse($body);
	}

	public function testSeverityIsRead(): void {
		$ticket = $this->parser->parse('{"ticket":{"id":1,"severity":"sev1"}}');
		$this->assertSame('sev1', $ticket->severity);
	}

	/**
	 * A severity Zammad failed to render must not be mistaken for a real one,
	 * or every ticket would look like an escalation.
	 */
	public function testUnresolvedSeverityIsDiscardedAndReported(): void {
		$ticket = $this->parser->parse('{"ticket":{"id":1,"severity":"#{ticket.severity / no such method}"}}');
		$this->assertSame('', $ticket->severity);
		$this->assertContains('#{ticket.severity / no such method}', $ticket->unresolved);
	}

	public function testMissingSeverityIsEmpty(): void {
		$this->assertSame('', $this->parser->parse('{"ticket":{"id":1}}')->severity);
	}

	public static function dataOwner(): array {
		return [
			'placeholder id'   => [['owner_id' => 1, 'owner' => ['firstname' => '-', 'lastname' => '']], false],
			'real agent'       => [['owner_id' => 275, 'owner' => ['firstname' => 'Ada', 'lastname' => 'Lovelace']], true],
			'id only'          => [['owner_id' => 275], true],
			'placeholder name' => [['owner' => '-'], false],
			'name only'        => [['owner' => 'Ada Lovelace'], true],
			'absent'           => [[], false],
			'zero id'          => [['owner_id' => 0], false],
			'string id'        => [['owner_id' => '275'], true],
			'string placeholder id' => [['owner_id' => '1'], false],
		];
	}

	#[DataProvider('dataOwner')]
	public function testOwnerDetection(array $extra, bool $expected): void {
		$ticket = $this->parser->parse(json_encode(['ticket' => ['id' => 1] + $extra], JSON_THROW_ON_ERROR));
		$this->assertSame($expected, $ticket->hasOwner());
	}

	public function testRealOwnerNameIsComposed(): void {
		$ticket = $this->parser->parse(json_encode([
			'ticket' => ['id' => 1, 'owner_id' => 275, 'owner' => ['firstname' => 'Ada', 'lastname' => 'Lovelace']],
		], JSON_THROW_ON_ERROR));
		$this->assertSame('Ada Lovelace', $ticket->owner);
		$this->assertSame(275, $ticket->ownerId);
	}

	/**
	 * If Zammad could not render the owner the ticket must still be announced,
	 * silently dropping everything would be worse than a spurious notification.
	 */
	public function testUnresolvedOwnerIsTreatedAsUnassigned(): void {
		$ticket = $this->parser->parse('{"ticket":{"id":1,"owner":"#{ticket.owner.fullname / no such method}"}}');
		$this->assertFalse($ticket->hasOwner());
		$this->assertContains('#{ticket.owner.fullname / no such method}', $ticket->unresolved);
	}
}
