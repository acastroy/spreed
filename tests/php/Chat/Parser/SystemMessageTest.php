<?php
/**
 * @copyright Copyright (c) 2018 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Spreed\Tests\php\Chat\Parser;

use OCA\Spreed\Chat\Parser\SystemMessage;
use OCA\Spreed\Exceptions\ParticipantNotFoundException;
use OCA\Spreed\GuestManager;
use OCA\Spreed\Share\RoomShareProvider;
use OCP\Comments\IComment;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class SystemMessageTest extends TestCase {

	/** @var IUserManager|MockObject */
	protected $userManager;
	/** @var GuestManager|MockObject */
	protected $guestManager;
	/** @var IUserSession|MockObject */
	protected $userSession;
	/** @var RoomShareProvider|MockObject */
	protected $shareProvider;
	/** @var IRootFolder|MockObject */
	protected $rootFolder;
	/** @var IURLGenerator|MockObject */
	protected $url;
	/** @var IL10N|MockObject */
	protected $l;

	public function setUp() {
		parent::setUp();

		$this->userManager = $this->createMock(IUserManager::class);
		$this->guestManager = $this->createMock(GuestManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->shareProvider = $this->createMock(RoomShareProvider::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->expects($this->any())
			->method('t')
			->will($this->returnCallback(function($text, $parameters = []) {
				return vsprintf($text, $parameters);
			}));
		$this->l->expects($this->any())
			->method('n')
			->will($this->returnCallback(function($singular, $plural, $count, $parameters = []) {
				$text = $count === 1 ? $singular : $plural;
				return vsprintf(str_replace('%n', $count, $text), $parameters);
			}));
	}

	/**
	 * @param array $methods
	 * @return MockObject|SystemMessage
	 */
	protected function getParser(array $methods = []): SystemMessage {
		if (!empty($methods)) {
			return $this->getMockBuilder(SystemMessage::class)
				->setConstructorArgs([
					$this->userManager,
					$this->guestManager,
					$this->userSession,
					$this->shareProvider,
					$this->rootFolder,
					$this->url,
					$this->l,
				])
				->setMethods($methods)
				->getMock();
		}
		return new SystemMessage(
			$this->userManager,
			$this->guestManager,
			$this->userSession,
			$this->shareProvider,
			$this->rootFolder,
			$this->url,
			$this->l
		);
	}

	public function dataParseMessage(): array {
		return [
			['conversation_created', [], null, [
				'{actor} created the conversation',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['conversation_created', [], 'recipient', [
				'{actor} created the conversation',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['conversation_created', [], 'actor', [
				'You created the conversation',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['conversation_renamed', ['oldName' => 'old', 'newName' => 'new'], null, [
				'{actor} renamed the conversation from "old" to "new"',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['conversation_renamed', ['oldName' => 'old', 'newName' => 'new'], 'recipient', [
				'{actor} renamed the conversation from "old" to "new"',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['conversation_renamed', ['oldName' => 'old', 'newName' => 'new'], 'actor', [
				'You renamed the conversation from "old" to "new"',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_started', [], null, [
				'{actor} started a call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_started', [], 'recipient', [
				'{actor} started a call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_started', [], 'actor', [
				'You started a call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_joined', [], null, [
				'{actor} joined the call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_joined', [], 'recipient', [
				'{actor} joined the call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_joined', [], 'actor', [
				'You joined the call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_left', [], null, [
				'{actor} left the call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_left', [], 'recipient', [
				'{actor} left the call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_left', [], 'actor', [
				'You left the call',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['call_ended', [], null, [
				'tested by testParsecall', []
			]],
			['call_ended', [], 'recipient', [
				'tested by testParsecall', []
			]],
			['call_ended', [], 'actor', [
				'tested by testParsecall', []
			]],
			['guests_allowed', [], null, [
				'{actor} allowed guests',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['guests_allowed', [], 'recipient', [
				'{actor} allowed guests',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['guests_allowed', [], 'actor', [
				'You allowed guests',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['guests_disallowed', [], null, [
				'{actor} disallowed guests',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['guests_disallowed', [], 'recipient', [
				'{actor} disallowed guests',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['guests_disallowed', [], 'actor', [
				'You disallowed guests',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['password_set', [], null, [
				'{actor} set a password',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['password_set', [], 'recipient', [
				'{actor} set a password',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['password_set', [], 'actor', [
				'You set a password',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['password_removed', [], null, [
				'{actor} removed the password',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['password_removed', [], 'recipient', [
				'{actor} removed the password',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['password_removed', [], 'actor', [
				'You removed the password',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['user_added', ['user' => 'user'], null, [
				'{actor} added {user}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['user_added', ['user' => 'user'], 'recipient', [
				'{actor} added {user}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['user_added', ['user' => 'user'], 'user', [
				'{actor} added you',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['user_added', ['user' => 'user'], 'actor', [
				'You added {user}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['user_removed', ['user' => 'user'], null, [
				'{actor} removed {user}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['user_removed', ['user' => 'user'], 'recipient', [
				'{actor} removed {user}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['user_removed', ['user' => 'actor'], 'actor', [
				'{actor} left the conversation',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'actor', 'type' => 'user']],
			]],
			['user_removed', ['user' => 'user'], 'user', [
				'{actor} removed you',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['user_removed', ['user' => 'user'], 'actor', [
				'You removed {user}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_promoted', ['user' => 'user'], null, [
				'{actor} promoted {user} to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_promoted', ['user' => 'user'], 'recipient', [
				'{actor} promoted {user} to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_promoted', ['user' => 'user'], 'user', [
				'{actor} promoted you to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_promoted', ['user' => 'user'], 'actor', [
				'You promoted {user} to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_demoted', ['user' => 'user'], null, [
				'{actor} demoted {user} from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_demoted', ['user' => 'user'], 'recipient', [
				'{actor} demoted {user} from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_demoted', ['user' => 'user'], 'user', [
				'{actor} demoted you from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['moderator_demoted', ['user' => 'user'], 'actor', [
				'You demoted {user} from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'user', 'type' => 'user']],
			]],
			['guest_moderator_promoted', ['session' => 'moderator'], null, [
				'{actor} promoted {user} to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_promoted', ['session' => 'moderator'], 'recipient', [
				'{actor} promoted {user} to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_promoted', ['session' => 'moderator'], 'guest::user', [
				'{actor} promoted {user} to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_promoted', ['session' => 'moderator'], 'guest::moderator', [
				'{actor} promoted you to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_promoted', ['session' => 'moderator'], 'actor', [
				'You promoted {user} to moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_demoted', ['session' => 'moderator'], null, [
				'{actor} demoted {user} from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_demoted', ['session' => 'moderator'], 'recipient', [
				'{actor} demoted {user} from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_demoted', ['session' => 'moderator'], 'guest::user', [
				'{actor} demoted {user} from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_demoted', ['session' => 'moderator'], 'guest::moderator', [
				'{actor} demoted you from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['guest_moderator_demoted', ['session' => 'moderator'], 'actor', [
				'You demoted {user} from moderator',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'user' => ['id' => 'moderator', 'type' => 'guest']],
			]],
			['file_shared', ['share' => '42'], null, [
				'{file}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'file' => ['id' => 'file-from-share']],
			]],
			['file_shared', ['share' => '42'], 'recipient', [
				'{file}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'file' => ['id' => 'file-from-share']],
			]],
			['file_shared', ['share' => '42'], 'actor', [
				'{file}',
				['actor' => ['id' => 'actor', 'type' => 'user'], 'file' => ['id' => 'file-from-share']],
			]],
			['file_shared', ['share' => ShareNotFound::class], null, [
				'{actor} shared a file which is no longer available',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['file_shared', ['share' => InvalidPathException::class], 'recipient', [
				'{actor} shared a file which is no longer available',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
			['file_shared', ['share' => NotFoundException::class], 'actor', [
				'You shared a file which is no longer available',
				['actor' => ['id' => 'actor', 'type' => 'user']],
			]],
		];
	}

	/**
	 * @dataProvider dataParseMessage
	 * @param string $message
	 * @param array $parameters
	 * @param $recipientId
	 * @param array $expected
	 */
	public function testParseMessage(string $message, array $parameters, $recipientId, array $expected) {
		if ($recipientId && strpos($recipientId, 'guest::') === false) {
			$recipient = $this->createMock(IUser::class);
			$recipient->expects($this->atLeastOnce())
				->method('getUID')
				->willReturn($recipientId);
			$sessionId = null;
		} else if ($recipientId) {
			$recipient = null;
			$sessionId = substr($recipientId, strlen('guest::'));
		} else {
			$recipient = null;
			$sessionId = null;
		}

		/** @var IComment|MockObject $comment */
		$comment = $this->createMock(IComment::class);
		$comment->expects($this->once())
			->method('getMessage')
			->willReturn(json_encode([
				'message' => $message,
				'parameters' => $parameters,
			]));

		$parser = $this->getParser(['getActor', 'getUser', 'getGuest', 'parseCall', 'getFileFromShare']);
		$parser->expects($this->once())
			->method('getActor')
			->with($comment)
			->willReturn(['id' => 'actor', 'type' => 'user']);
		$parser->expects($this->any())
			->method('getUser')
			->with($parameters['user'] ?? 'user')
			->willReturn(['id' => $parameters['user'] ?? 'user', 'type' => 'user']);
		$parser->expects($this->any())
			->method('getGuest')
			->with($parameters['session'] ?? 'guest')
			->willReturn(['id' => $parameters['session'] ?? 'guest', 'type' => 'guest']);
		self::invokePrivate($parser, 'recipient', [$recipient]);
		self::invokePrivate($parser, 'sessionId', [$sessionId]);

		if ($message === 'call_ended') {
			$parser->expects($this->once())
				->method('parseCall')
				->with($parameters)
				->willReturn($expected);
		} else {
			$parser->expects($this->never())
				->method('parseCall');
		}

		if ($message === 'file_shared') {
			if (is_subclass_of($parameters['share'], \Exception::class)) {
				$parser->expects($this->once())
					->method('getFileFromShare')
					->with($parameters['share'])
					->willThrowException(new $parameters['share']());
			} else {
				$parser->expects($this->once())
					->method('getFileFromShare')
					->with($parameters['share'])
					->willReturn(['id' => 'file-from-share']);
				$comment->expects($this->once())
					->method('setVerb')
					->with('comment');
			}
		} else {
			$parser->expects($this->never())
				->method('getFileFromShare');
		}

		$comment->expects($this->once())
			->method('setMessage')
			->with($message);

		$this->assertSame($expected, $parser->parseMessage($comment));
	}

	public function dataParseMessageThrows(): array {
		return [
			[null],
			['not json'],
			[json_encode('not a json array')],
			[json_encode(['message' => 'unkown_subject', 'parameters' => []])],
		];
	}

	/**
	 * @dataProvider dataParseMessageThrows
	 * @param string|null $return
	 * @expectedException \OutOfBoundsException
	 */
	public function testParseMessageThrows($return) {
		/** @var IComment|MockObject $comment */
		$comment = $this->createMock(IComment::class);
		$comment->expects($this->once())
			->method('getMessage')
			->willReturn($return);

		$parser = $this->getParser(['getActor']);
		$parser->expects($this->any())
			->method('getActor')
			->with($comment)
			->willReturn(['id' => 'actor', 'type' => 'user']);

		$parser->parseMessage($comment);
	}

	public function testSetUserInfoEmptyToUser() {
		/** @var IUser $user */
		$user = $this->createMock(IUser::class);
		/** @var IL10N $l */
		$l = $this->createMock(IL10N::class);

		$parser = $this->getParser();
		$this->assertNull(self::invokePrivate($parser, 'recipient'));
		$this->assertNull(self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($this->l, self::invokePrivate($parser, 'l'));
		$parser->setUserInfo($l, $user);
		$this->assertSame($user, self::invokePrivate($parser, 'recipient'));
		$this->assertNull(self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($l, self::invokePrivate($parser, 'l'));
	}

	public function testSetUserInfoUser1ToUser2() {
		/** @var IUser $user1 */
		$user1 = $this->createMock(IUser::class);
		/** @var IUser $user2 */
		$user2 = $this->createMock(IUser::class);
		/** @var IL10N $l */
		$l = $this->createMock(IL10N::class);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user1);

		$parser = $this->getParser();
		$this->assertSame($user1, self::invokePrivate($parser, 'recipient'));
		$this->assertNull(self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($this->l, self::invokePrivate($parser, 'l'));
		$parser->setUserInfo($l, $user2);
		$this->assertSame($user2, self::invokePrivate($parser, 'recipient'));
		$this->assertNull(self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($l, self::invokePrivate($parser, 'l'));
	}

	public function testSetUserInfoUserToEmpty() {
		/** @var IUser $user1 */
		$user = $this->createMock(IUser::class);
		/** @var IL10N $l */
		$l = $this->createMock(IL10N::class);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$parser = $this->getParser();
		$this->assertSame($user, self::invokePrivate($parser, 'recipient'));
		$this->assertNull(self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($this->l, self::invokePrivate($parser, 'l'));
		$parser->setUserInfo($l, null);
		$this->assertNull(self::invokePrivate($parser, 'recipient'));
		$this->assertNull(self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($l, self::invokePrivate($parser, 'l'));
	}

	public function testSetGuestInfoEmptyToGuest() {
		/** @var IL10N $l */
		$l = $this->createMock(IL10N::class);

		$parser = $this->getParser();
		$this->assertNull(self::invokePrivate($parser, 'recipient'));
		$this->assertNull(self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($this->l, self::invokePrivate($parser, 'l'));
		$parser->setGuestInfo($l, 'session');
		$this->assertNull(self::invokePrivate($parser, 'recipient'));
		$this->assertSame('session', self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($l, self::invokePrivate($parser, 'l'));
	}

	public function testSetGuestInfoGuest1ToGuest2() {
		/** @var IL10N $l */
		$l = $this->createMock(IL10N::class);

		$parser = $this->getParser();
		self::invokePrivate($parser, 'sessionId', ['session1']);

		$this->assertNull(self::invokePrivate($parser, 'recipient'));
		$this->assertSame('session1', self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($this->l, self::invokePrivate($parser, 'l'));
		$parser->setGuestInfo($l, 'session2');
		$this->assertNull(self::invokePrivate($parser, 'recipient'));
		$this->assertSame('session2', self::invokePrivate($parser, 'sessionId'));
		$this->assertSame($l, self::invokePrivate($parser, 'l'));
	}

	public function testGetFileFromShareForGuest() {
		$node = $this->createMock(Node::class);
		$node->expects($this->once())
			->method('getId')
			->willReturn('54');
		$node->expects($this->once())
			->method('getName')
			->willReturn('name');

		$share = $this->createMock(IShare::class);
		$share->expects($this->once())
			->method('getNode')
			->willReturn($node);
		$share->expects($this->once())
			->method('getToken')
			->willReturn('token');

		$this->shareProvider->expects($this->once())
			->method('getShareById')
			->with('23')
			->willReturn($share);

		$this->url->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('files_sharing.sharecontroller.showShare', [
				'token' => 'token',
			])
			->willReturn('absolute-link');

		$parser = $this->getParser();
		$this->assertSame([
			'type' => 'file',
			'id' => '54',
			'name' => 'name',
			'path' => 'name',
			'link' => 'absolute-link',
		], self::invokePrivate($parser, 'getFileFromShare', ['23']));
	}

	public function testGetFileFromShareForOwner() {
		$node = $this->createMock(Node::class);
		$node->expects($this->exactly(2))
			->method('getId')
			->willReturn('54');
		$node->expects($this->once())
			->method('getName')
			->willReturn('name');
		$node->expects($this->once())
			->method('getPath')
			->willReturn('/owner/files/path/to/file/name');

		$share = $this->createMock(IShare::class);
		$share->expects($this->once())
			->method('getNode')
			->willReturn($node);

		$this->shareProvider->expects($this->once())
			->method('getShareById')
			->with('23')
			->willReturn($share);

		$this->url->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', [
				'fileid' => '54',
			])
			->willReturn('absolute-link-owner');

		$this->userSession->expects($this->exactly(2))
			->method('getUser')
			->willReturn($this->createMock(IUser::class));

		$parser = $this->getParser();
		$this->assertSame([
			'type' => 'file',
			'id' => '54',
			'name' => 'name',
			'path' => 'path/to/file/name',
			'link' => 'absolute-link-owner',
		], self::invokePrivate($parser, 'getFileFromShare', ['23']));
	}

	public function testGetFileFromShareForRecipient() {
		$node = $this->createMock(Node::class);
		$node->expects($this->exactly(3))
			->method('getId')
			->willReturn('54');
		$node->expects($this->once())
			->method('getName')
			->willReturn('name');

		$share = $this->createMock(IShare::class);
		$share->expects($this->once())
			->method('getNode')
			->willReturn($node);

		$recipient = $this->createMock(IUser::class);
		$recipient->expects($this->once())
			->method('getUID')
			->willReturn('user');

		$this->shareProvider->expects($this->once())
			->method('getShareById')
			->with('23')
			->willReturn($share);

		$file = $this->createMock(Node::class);
		$file->expects($this->once())
			->method('getName')
			->willReturn('different');
		$file->expects($this->once())
			->method('getPath')
			->willReturn('/user/files/Shared/different');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('getById')
			->with('54')
			->willReturn([$file]);

		$this->rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('user')
			->willReturn($userFolder);

		$this->url->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', [
				'fileid' => '54',
			])
			->willReturn('absolute-link-owner');

		$this->userSession->expects($this->exactly(2))
			->method('getUser')
			->willReturnOnConsecutiveCalls(
				$recipient,
				$this->createMock(IUser::class)
			);

		$parser = $this->getParser();
		$this->assertSame([
			'type' => 'file',
			'id' => '54',
			'name' => 'different',
			'path' => 'Shared/different',
			'link' => 'absolute-link-owner',
		], self::invokePrivate($parser, 'getFileFromShare', ['23']));
	}

	/**
	 * @expectedException \OCP\Files\NotFoundException
	 */
	public function testGetFileFromShareForRecipientThrows() {
		$node = $this->createMock(Node::class);
		$node->expects($this->once())
			->method('getId')
			->willReturn('54');
		$node->expects($this->once())
			->method('getName')
			->willReturn('name');

		$share = $this->createMock(IShare::class);
		$share->expects($this->once())
			->method('getNode')
			->willReturn($node);

		$recipient = $this->createMock(IUser::class);
		$recipient->expects($this->once())
			->method('getUID')
			->willReturn('user');

		$this->shareProvider->expects($this->once())
			->method('getShareById')
			->with('23')
			->willReturn($share);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->expects($this->once())
			->method('getById')
			->with('54')
			->willReturn([]);

		$this->rootFolder->expects($this->once())
			->method('getUserFolder')
			->with('user')
			->willReturn($userFolder);

		$this->url->expects($this->never())
			->method('linkToRouteAbsolute');

		$this->userSession->expects($this->exactly(2))
			->method('getUser')
			->willReturnOnConsecutiveCalls(
				$recipient,
				$this->createMock(IUser::class)
			);

		$parser = $this->getParser();
		self::invokePrivate($parser, 'getFileFromShare', ['23']);
	}

	/**
	 * @expectedException \OCP\Share\Exceptions\ShareNotFound
	 */
	public function testGetFileFromShareThrows() {

		$this->shareProvider->expects($this->once())
			->method('getShareById')
			->with('23')
			->willThrowException(new ShareNotFound());

		$parser = $this->getParser();
		self::invokePrivate($parser, 'getFileFromShare', ['23']);
	}

	public function dataGetActor(): array {
		return [
			['users', [], ['user'], ['user']],
			['guests', ['guest'], [], ['guest']],
		];
	}

	/**
	 * @dataProvider dataGetActor
	 * @param string $actorType
	 * @param array $guestData
	 * @param array $userData
	 * @param array $expected
	 */
	public function testGetActor(string $actorType, array $guestData, array $userData, array $expected) {
		$chatMessage = $this->createMock(IComment::class);
		$chatMessage->expects($this->once())
			->method('getActorType')
			->willReturn($actorType);
		$chatMessage->expects($this->once())
			->method('getActorId')
			->willReturn('author-id');

		$parser = $this->getParser(['getGuest', 'getUser']);
		if (empty($guestData)) {
			$parser->expects($this->never())
				->method('getGuest');
		} else {
			$parser->expects($this->once())
				->method('getGuest')
				->with('author-id')
				->willReturn($guestData);
		}

		if (empty($userData)) {
			$parser->expects($this->never())
				->method('getUser');
		} else {
			$parser->expects($this->once())
				->method('getUser')
				->with('author-id')
				->willReturn($userData);
		}

		$this->assertSame($expected, self::invokePrivate($parser, 'getActor', [$chatMessage]));
	}

	public function dataGetUser(): array {
		return [
			['test', [], false, 'Test'],
			['foo', ['admin' => 'Admin'], false, 'Bar'],
			['admin', ['admin' => 'Administrator'], true, 'Administrator'],
		];
	}

	/**
	 * @dataProvider dataGetUser
	 * @param string $uid
	 * @param array $cache
	 * @param bool $cacheHit
	 * @param string $name
	 */
	public function testGetUser(string $uid, array $cache, bool $cacheHit, string $name) {
		$parser = $this->getParser(['getDisplayName']);

		self::invokePrivate($parser, 'displayNames', [$cache]);

		if (!$cacheHit) {
			$parser->expects($this->once())
				->method('getDisplayName')
				->with($uid)
				->willReturn($name);
		} else {
			$parser->expects($this->never())
				->method('getDisplayName');
		}

		$result = self::invokePrivate($parser, 'getUser', [$uid]);
		$this->assertSame('user', $result['type']);
		$this->assertSame($uid, $result['id']);
		$this->assertSame($name, $result['name']);
	}

	public function dataGetDisplayName(): array {
		return [
			['test', true, 'Test'],
			['foo', false, 'foo'],
		];
	}

	/**
	 * @dataProvider dataGetDisplayName
	 * @param string $uid
	 * @param bool $validUser
	 * @param string $name
	 */
	public function testGetDisplayName(string $uid, bool $validUser, string $name) {
		$parser = $this->getParser();

		if ($validUser) {
			$user = $this->createMock(IUser::class);
			$user->expects($this->once())
				->method('getDisplayName')
				->willReturn($name);
			$this->userManager->expects($this->once())
				->method('get')
				->with($uid)
				->willReturn($user);
		} else {
			$this->userManager->expects($this->once())
				->method('get')
				->with($uid)
				->willReturn(null);
		}

		$this->assertSame($name, self::invokePrivate($parser, 'getDisplayName', [$uid]));
	}

	public function testGetGuest() {
		$sessionHash = sha1('name');
		$parser = $this->getParser(['getGuestName']);
		$parser->expects($this->once())
			->method('getGuestName')
			->with($sessionHash)
			->willReturn('name');

		$this->assertSame([
			'type' => 'guest',
			'id' => $sessionHash,
			'name' => 'name',
		], self::invokePrivate($parser, 'getGuest', [$sessionHash]));

		// Cached call: no call to getGuestName() again
		$this->assertSame([
			'type' => 'guest',
			'id' => $sessionHash,
			'name' => 'name',
		], self::invokePrivate($parser, 'getGuest', [$sessionHash]));
	}

	public function testGetGuestName() {
		$sessionHash = sha1('name');
		$this->guestManager->expects($this->once())
			->method('getNameBySessionHash')
			->with($sessionHash)
			->willReturn('name');

		$parser = $this->getParser();
		$this->assertSame('name (guest)', self::invokePrivate($parser, 'getGuestName', [$sessionHash]));
	}

	public function testGetGuestNameThrows() {
		$sessionHash = sha1('name');
		$this->guestManager->expects($this->once())
			->method('getNameBySessionHash')
			->with($sessionHash)
			->willThrowException(new ParticipantNotFoundException());

		$parser = $this->getParser();
		$this->assertSame('Guest', self::invokePrivate($parser, 'getGuestName', [$sessionHash]));
	}

	public function dataParseCall(): array {
		return [
			'1 user + guests' => [
				['users' => ['user1'], 'guests' => 3, 'duration' => 42],
				[
					'Call with {user1} and 3 guests (Duration "duration")',
					['user1' => ['data' => 'user1']],
				],
			],
			'2 users' => [
				['users' => ['user1', 'user2'], 'guests' => 0, 'duration' => 42],
				[
					'Call with {user1} and {user2} (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2']],
				],
			],
			'2 users + guests' => [
				['users' => ['user1', 'user2'], 'guests' => 1, 'duration' => 42],
				[
					'Call with {user1}, {user2} and 1 guest (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2']],
				],
			],
			'3 users' => [
				['users' => ['user1', 'user2', 'user3'], 'guests' => 0, 'duration' => 42],
				[
					'Call with {user1}, {user2} and {user3} (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3']],
				],
			],
			'3 users + guests' => [
				['users' => ['user1', 'user2', 'user3'], 'guests' => 22, 'duration' => 42],
				[
					'Call with {user1}, {user2}, {user3} and 22 guests (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3']],
				],
			],
			'4 users' => [
				['users' => ['user1', 'user2', 'user3', 'user4'], 'guests' => 0, 'duration' => 42],
				[
					'Call with {user1}, {user2}, {user3} and {user4} (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3'], 'user4' => ['data' => 'user4']],
				],
			],
			'4 users + guests' => [
				['users' => ['user1', 'user2', 'user3', 'user4'], 'guests' => 4, 'duration' => 42],
				[
					'Call with {user1}, {user2}, {user3}, {user4} and 4 guests (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3'], 'user4' => ['data' => 'user4']],
				],
			],
			'5 users' => [
				['users' => ['user1', 'user2', 'user3', 'user4', 'user5'], 'guests' => 0, 'duration' => 42],
				[
					'Call with {user1}, {user2}, {user3}, {user4} and {user5} (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3'], 'user4' => ['data' => 'user4'], 'user5' => ['data' => 'user5']],
				],
			],
			'5 users + guests' => [
				['users' => ['user1', 'user2', 'user3', 'user4', 'user5'], 'guests' => 1, 'duration' => 42],
				[
					'Call with {user1}, {user2}, {user3}, {user4} and 2 others (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3'], 'user4' => ['data' => 'user4']],
				],
			],
			'6 users' => [
				['users' => ['user1', 'user2', 'user3', 'user4', 'user5', 'user6'], 'guests' => 0, 'duration' => 42],
				[
					'Call with {user1}, {user2}, {user3}, {user4} and 2 others (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3'], 'user4' => ['data' => 'user4']],
				],
			],
			'6 users + guests' => [
				['users' => ['user1', 'user2', 'user3', 'user4', 'user5', 'user6'], 'guests' => 2, 'duration' => 42],
				[
					'Call with {user1}, {user2}, {user3}, {user4} and 4 others (Duration "duration")',
					['user1' => ['data' => 'user1'], 'user2' => ['data' => 'user2'], 'user3' => ['data' => 'user3'], 'user4' => ['data' => 'user4']],
				],
			],
		];
	}

	/**
	 * @dataProvider dataParseCall
	 * @param array $parameters
	 * @param array $expected
	 */
	public function testParseCall(array $parameters, array $expected) {
		$parser = $this->getParser(['getDuration', 'getUser']);
		$parser->expects($this->once())
			->method('getDuration')
			->with(42)
			->willReturn('"duration"');

		$parser->expects($this->any())
			->method('getUser')
			->willReturnCallback(function($user) {
				return ['data' => $user];
			});

		$this->assertSame($expected, self::invokePrivate($parser, 'parseCall', [$parameters]));
	}

	public function dataGetDuration(): array {
		return [
			[30, '0:30'],
			[140, '2:20'],
			[5421, '1:30:21'],
			[7221, '2:00:21'],
		];
	}

	/**
	 * @dataProvider dataGetDuration
	 * @param int $seconds
	 * @param string $expected
	 */
	public function testGetDuration(int $seconds, string $expected) {
		$parser = $this->getParser();
		$this->assertSame($expected, self::invokePrivate($parser, 'getDuration', [$seconds]));
	}
}
