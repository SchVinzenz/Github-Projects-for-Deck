<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DeckGithubSync\Service;

use Psr\Log\LoggerInterface;

/**
 * Typed wrapper around Projects v2 GraphQL.
 */
class GithubProjectService {
	public function __construct(
		private GithubClientService $client,
		private LoggerInterface $logger,
	) {
	}

	public function resolveProjectId(string $userId, string $owner, int $number, string $ownerType = 'organization'): ?string {
		$field = $ownerType === 'user' ? 'user' : 'organization';
		$q = "query(\$login:String!,\$num:Int!){ $field(login:\$login){ projectV2(number:\$num){ id title } } }";
		$res = $this->client->graphql($userId, $q, ['login' => $owner, 'num' => $number]);
		return $res['data'][$field]['projectV2']['id'] ?? null;
	}

	/** @return array{fields: array, statusFieldId: string, options: array} */
	public function getFields(string $userId, string $projectId): array {
		$q = 'query($pid:ID!){ node(id:$pid){ ... on ProjectV2{ fields(first:50){ nodes{
			... on ProjectV2FieldCommon{ __typename id name dataType }
			... on ProjectV2SingleSelectField{ id name options{ id name color } }
		} } } } }';
		$res = $this->client->graphql($userId, $q, ['pid' => $projectId]);
		$nodes = $res['data']['node']['fields']['nodes'] ?? [];
		$statusFieldId = '';
		$options = [];
		foreach ($nodes as $f) {
			if (($f['name'] ?? '') === 'Status' && isset($f['options'])) {
				$statusFieldId = $f['id'];
				foreach ($f['options'] as $o) {
					$options[$o['name']] = $o['id'];
				}
			}
		}
		return ['fields' => $nodes, 'statusFieldId' => $statusFieldId, 'options' => $options];
	}

	/** @return array{items: array, hasNext: bool, cursor: ?string} */
	public function listItems(string $userId, string $projectId, ?string $after = null): array {
		$q = 'query($pid:ID!,$after:String){ node(id:$pid){ ... on ProjectV2{
			items(first:50, after:$after){ pageInfo{ hasNextPage endCursor }
			nodes{ id updatedAt archivedAt
				content{ __typename
					... on DraftIssue{ id title body updatedAt }
					... on Issue{ id number title body state closed url repository{nameWithOwner} assignees(first:10){nodes{login}} labels(first:10){nodes{name}} }
					... on PullRequest{ id number title body state merged url }
				}
				fieldValues(first:20){ nodes{ __typename
					... on ProjectV2ItemFieldSingleSelectValue{ name optionId field{ ... on ProjectV2FieldCommon{ id name } } }
					... on ProjectV2ItemFieldTextValue{ text field{ ... on ProjectV2FieldCommon{ id name } } }
					... on ProjectV2ItemFieldDateValue{ date field{ ... on ProjectV2FieldCommon{ id name } } }
				} }
			} } } } }';
		$res = $this->client->graphql($userId, $q, ['pid' => $projectId, 'after' => $after]);
		$conn = $res['data']['node']['items'] ?? ['nodes' => [], 'pageInfo' => []];
		return [
			'items' => $conn['nodes'] ?? [],
			'hasNext' => (bool)($conn['pageInfo']['hasNextPage'] ?? false),
			'cursor' => $conn['pageInfo']['endCursor'] ?? null,
		];
	}

	public function addDraft(string $userId, string $projectId, string $title, string $body = ''): ?string {
		$m = 'mutation($pid:ID!,$t:String!,$b:String!){ addProjectV2DraftIssue(input:{projectId:$pid title:$t body:$b}){ projectItem{ id } } }';
		$res = $this->client->graphql($userId, $m, ['pid' => $projectId, 't' => $title, 'b' => $body]);
		return $res['data']['addProjectV2DraftIssue']['projectItem']['id'] ?? null;
	}

	public function updateDraft(string $userId, string $itemId, string $draftContentId, string $title, string $body): void {
		$m = 'mutation($item:ID!,$t:String!,$b:String!){ updateProjectV2DraftIssue(input:{draftIssueId:$item title:$t body:$b}){ draftIssue{ id } } }';
		// updateProjectV2DraftIssue expects the DraftIssue node id, fallback to item id
		$this->client->graphql($userId, $m, ['item' => $draftContentId !== '' ? $draftContentId : $itemId, 't' => $title, 'b' => $body]);
	}

	public function setStatus(string $userId, string $projectId, string $itemId, string $fieldId, string $optionId): void {
		$m = 'mutation($pid:ID!,$item:ID!,$fid:ID!,$opt:String!){ updateProjectV2ItemFieldValue(input:{projectId:$pid itemId:$item fieldId:$fid value:{singleSelectOptionId:$opt}}){ projectV2Item{ id } } }';
		$this->client->graphql($userId, $m, ['pid' => $projectId, 'item' => $itemId, 'fid' => $fieldId, 'opt' => $optionId]);
	}

	public function setDate(string $userId, string $projectId, string $itemId, string $fieldId, ?string $date): void {
		if ($date === null || $date === '') {
			$m = 'mutation($pid:ID!,$item:ID!,$fid:ID!){ clearProjectV2ItemFieldValue(input:{projectId:$pid itemId:$item fieldId:$fid}){ projectV2Item{ id } } }';
			$this->client->graphql($userId, $m, ['pid' => $projectId, 'item' => $itemId, 'fid' => $fieldId]);
			return;
		}
		$m = 'mutation($pid:ID!,$item:ID!,$fid:ID!,$d:Date!){ updateProjectV2ItemFieldValue(input:{projectId:$pid itemId:$item fieldId:$fid value:{date:$d}}){ projectV2Item{ id } } }';
		$this->client->graphql($userId, $m, ['pid' => $projectId, 'item' => $itemId, 'fid' => $fieldId, 'd' => substr($date, 0, 10)]);
	}

	public function deleteItem(string $userId, string $projectId, string $itemId): void {
		$m = 'mutation($pid:ID!,$item:ID!){ deleteProjectV2Item(input:{projectId:$pid itemId:$item}){ deletedItemId } }';
		$this->client->graphql($userId, $m, ['pid' => $projectId, 'item' => $itemId]);
	}

	public function updateIssue(string $userId, string $issueNodeId, ?string $title = null, ?string $body = null, ?bool $closed = null): void {
		$m = 'mutation($id:ID!,$t:String,$b:String,$s:[String!]){ updateIssue(input:{id:$id title:$t body:$b state:$s}){ issue{ id } } }';
		$state = $closed === null ? null : [$closed ? 'CLOSED' : 'OPEN'];
		// GraphQL updateIssue treats omitted optional args as unchanged only when variable is absent;
		// send minimal payload via REST fallback if needed. Here variables with null are ignored by server.
		$this->client->graphql($userId, $m, ['id' => $issueNodeId, 't' => $title, 'b' => $body, 's' => $state]);
	}

	/** Update issue via REST (repo-scoped, robust for title/body/state/labels/assignees). */
	public function updateIssueRest(string $userId, string $repo, int $number, array $fields): void {
		$this->client->rest($userId, 'PATCH', '/repos/' . $repo . '/issues/' . $number, $fields);
	}

	public function setIssueLabels(string $userId, string $repo, int $number, array $labels): void {
		$this->client->rest($userId, 'PUT', '/repos/' . $repo . '/issues/' . $number . '/labels', ['labels' => array_values($labels)]);
	}

	public function setIssueAssignees(string $userId, string $repo, int $number, array $assignees): void {
		$this->client->rest($userId, 'POST', '/repos/' . $repo . '/issues/' . $number . '/assignees', ['assignees' => array_values($assignees)]);
	}

	/** @return array<int, array{id:int,body:string,user:string,created_at:string}> */
	public function getIssueComments(string $userId, string $repo, int $number): array {
		$data = $this->client->rest($userId, 'GET', '/repos/' . $repo . '/issues/' . $number . '/comments?per_page=100');
		$out = [];
		foreach ($data as $c) {
			if (!isset($c['id'])) {
				continue;
			}
			$out[] = ['id' => (int)$c['id'], 'body' => $c['body'] ?? '', 'user' => $c['user']['login'] ?? '', 'created_at' => $c['created_at'] ?? ''];
		}
		return $out;
	}

	public function addIssueComment(string $userId, string $repo, int $number, string $body): void {
		$this->client->rest($userId, 'POST', '/repos/' . $repo . '/issues/' . $number . '/comments', ['body' => $body]);
	}

	public static function repoOf(array $item): ?string {
		return $item['content']['repository']['nameWithOwner'] ?? null;
	}

	public static function numberOf(array $item): ?int {
		return isset($item['content']['number']) ? (int)$item['content']['number'] : null;
	}

	public static function labelsOf(array $item): array {
		$out = [];
		foreach ($item['content']['labels']['nodes'] ?? [] as $l) {
			$out[] = $l['name'] ?? '';
		}
		return array_values(array_filter($out));
	}

	public static function assigneesOf(array $item): array {
		$out = [];
		foreach ($item['content']['assignees']['nodes'] ?? [] as $a) {
			$out[] = $a['login'] ?? '';
		}
		return array_values(array_filter($out));
	}

	public static function statusOf(array $item): string {
		foreach ($item['fieldValues']['nodes'] ?? [] as $fv) {
			if (($fv['__typename'] ?? '') === 'ProjectV2ItemFieldSingleSelectValue'
				&& ($fv['field']['name'] ?? '') === 'Status') {
				return $fv['name'] ?? '';
			}
		}
		return '';
	}
}
