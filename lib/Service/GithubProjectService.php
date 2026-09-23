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

	public function resolveProjectId(string $userId, string $owner, int $number, string $ownerType = 'auto'): ?string {
		if ($ownerType === 'auto') {
			$failure = null;
			foreach (['user', 'organization'] as $type) {
				try {
					$id = $this->resolveProjectId($userId, $owner, $number, $type);
					if ($id !== null) {
						return $id;
					}
				} catch (\Throwable $e) {
					$failure = $e;
				}
			}
			if ($failure !== null) {
				throw $failure;
			}
			return null;
		}
		if (!in_array($ownerType, ['user', 'organization'], true)) {
			throw new \InvalidArgumentException('Invalid GitHub owner type');
		}
		$field = $ownerType === 'user' ? 'user' : 'organization';
		$q = "query(\$login:String!,\$num:Int!){ $field(login:\$login){ projectV2(number:\$num){ id title } } }";
		$res = $this->client->graphql($userId, $q, ['login' => $owner, 'num' => $number]);
		return $res['data'][$field]['projectV2']['id'] ?? null;
	}

	/** Repositories where the connected user can create issues. */
	public function listAvailableRepositories(string $userId): array {
		if ($this->client->getUserAccessToken($userId) === '') {
			throw new \RuntimeException('Connect a GitHub account to list repositories');
		}
		$repos = [];
		$page = 1;
		do {
			$data = $this->client->rest($userId, 'GET', '/user/repos?per_page=100&page=' . $page . '&affiliation=owner,collaborator,organization_member');
			foreach ($data as $repo) {
				if (!empty($repo['full_name']) && ($repo['has_issues'] ?? false) && ($repo['permissions']['push'] ?? false)) {
					$repos[$repo['full_name']] = $repo['full_name'];
				}
			}
			$page++;
		} while (count($data) === 100);
		natcasesort($repos);
		return array_values($repos);
	}

	public function getRepositoryNodeId(string $userId, string $repository): string {
		if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
			throw new \InvalidArgumentException('Invalid GitHub repository');
		}
		$repo = $this->client->rest($userId, 'GET', '/repos/' . $repository);
		if (empty($repo['node_id']) || !($repo['has_issues'] ?? false) || !($repo['permissions']['push'] ?? false)) {
			throw new \RuntimeException('Repository is unavailable or issues cannot be created');
		}
		return (string)$repo['node_id'];
	}

	/** Projects owned by the connected user and their organizations. */
	public function listAvailableProjects(string $userId): array {
		$owners = [];
		$after = null;
		do {
			$q = 'query($after:String){ viewer{ login organizations(first:100,after:$after){ nodes{login} pageInfo{hasNextPage endCursor} } } }';
			$res = $this->client->graphql($userId, $q, ['after' => $after], true);
			$viewer = $res['data']['viewer'] ?? null;
			if (!is_array($viewer) || empty($viewer['login'])) {
				throw new \RuntimeException('GitHub user projects could not be listed');
			}
			$owners['user:' . strtolower($viewer['login'])] = ['type' => 'user', 'login' => $viewer['login']];
			$organizations = $viewer['organizations'] ?? [];
			foreach ($organizations['nodes'] ?? [] as $org) {
				if (!empty($org['login'])) {
					$owners['organization:' . strtolower($org['login'])] = ['type' => 'organization', 'login' => $org['login']];
				}
			}
			$after = ($organizations['pageInfo']['hasNextPage'] ?? false) ? ($organizations['pageInfo']['endCursor'] ?? null) : null;
			if (($organizations['pageInfo']['hasNextPage'] ?? false) && $after === null) {
				throw new \RuntimeException('GitHub organization pagination failed');
			}
		} while ($after !== null);

		$projects = [];
		foreach ($owners as $owner) {
			$field = $owner['type'] === 'user' ? 'user' : 'organization';
			$after = null;
			do {
				$q = "query(\$login:String!,\$after:String){ $field(login:\$login){ projectsV2(first:100,after:\$after){ nodes{id number title url} pageInfo{hasNextPage endCursor} } } }";
				try {
					$res = $this->client->graphql($userId, $q, ['login' => $owner['login'], 'after' => $after], true);
				} catch (\Throwable $e) {
					if ($owner['type'] === 'organization' && $after === null
						&& str_starts_with($e->getMessage(), 'GitHub GraphQL request failed:')) {
						$this->logger->warning('deckgithubsync: skipping inaccessible organization projects', ['owner' => $owner['login'], 'exceptionType' => get_class($e)]);
						continue 2;
					}
					throw $e;
				}
				$connection = $res['data'][$field]['projectsV2'] ?? null;
				if (!is_array($connection)) {
					if ($owner['type'] === 'organization' && $after === null) {
						$this->logger->warning('deckgithubsync: skipping unavailable organization projects', ['owner' => $owner['login']]);
						continue 2;
					}
					throw new \RuntimeException('GitHub projects could not be listed for ' . $owner['login']);
				}
				foreach ($connection['nodes'] ?? [] as $project) {
					if (!empty($project['id']) && isset($project['number'], $project['title'])) {
						$projects[$project['id']] = [
							'id' => $project['id'],
							'owner' => $owner['login'],
							'number' => (int)$project['number'],
							'title' => $project['title'],
							'url' => $project['url'] ?? '',
						];
					}
				}
				$after = ($connection['pageInfo']['hasNextPage'] ?? false) ? ($connection['pageInfo']['endCursor'] ?? null) : null;
				if (($connection['pageInfo']['hasNextPage'] ?? false) && $after === null) {
					throw new \RuntimeException('GitHub project pagination failed');
				}
			} while ($after !== null);
		}
		$projects = array_values($projects);
		usort($projects, static fn (array $a, array $b) => strcasecmp($a['owner'] . '/' . $a['title'], $b['owner'] . '/' . $b['title']));
		return $projects;
	}

	/** @return array{fields: array, statusFieldId: string, dateFieldId: string, startDateFieldId: string, options: array} */
	public function getFields(string $userId, string $projectId): array {
		$q = 'query($pid:ID!){ node(id:$pid){ ... on ProjectV2{ fields(first:100){ nodes{
			... on ProjectV2FieldCommon{ __typename id name dataType }
			... on ProjectV2SingleSelectField{ id name options{ id name color description } }
		} } } } }';
		$res = $this->client->graphql($userId, $q, ['pid' => $projectId]);
		$nodes = $res['data']['node']['fields']['nodes'] ?? [];
		$statusFieldId = '';
		$dateFieldId = '';
		$startDateFieldId = '';
		$dateFields = [];
		$options = [];
		foreach ($nodes as $f) {
			if (($f['name'] ?? '') === 'Status' && isset($f['options'])) {
				$statusFieldId = $f['id'];
				foreach ($f['options'] as $o) {
					$options[$o['name']] = $o['id'];
				}
			}
			if (($f['dataType'] ?? '') === 'DATE') {
				$dateFields[] = $f;
				$name = mb_strtolower((string)($f['name'] ?? ''));
				if (str_contains($name, 'start')) {
					$startDateFieldId = $f['id'];
				} elseif (str_contains($name, 'due') || str_contains($name, 'target') || str_contains($name, 'end') || str_contains($name, 'fällig')) {
					$dateFieldId = $f['id'];
				}
			}
		}
		if ($dateFieldId === '' && count($dateFields) === 1) {
			$dateFieldId = $dateFields[0]['id'];
		}
		return ['fields' => $nodes, 'statusFieldId' => $statusFieldId, 'dateFieldId' => $dateFieldId, 'startDateFieldId' => $startDateFieldId, 'options' => $options];
	}

	/** Roadmap and Deck Gantt use separate start and due date fields. */
	public function ensureDateFields(string $userId, string $projectId, string $startFieldId, string $dueFieldId): array {
		if ($startFieldId === '') {
			$startFieldId = $this->createDateField($userId, $projectId, 'Start date');
		}
		if ($dueFieldId === '') {
			$dueFieldId = $this->createDateField($userId, $projectId, 'Due date');
		}
		return ['startDateFieldId' => $startFieldId, 'dateFieldId' => $dueFieldId];
	}

	private function createDateField(string $userId, string $projectId, string $name): string {
		$q = 'mutation($project:ID!,$name:String!){ createProjectV2Field(input:{projectId:$project name:$name dataType:DATE}){ projectV2Field{ ... on ProjectV2FieldCommon{id} } } }';
		$res = $this->client->graphql($userId, $q, ['project' => $projectId, 'name' => $name]);
		$id = (string)($res['data']['createProjectV2Field']['projectV2Field']['id'] ?? '');
		if ($id === '') {
			throw new \RuntimeException('GitHub date field could not be created: ' . $name);
		}
		return $id;
	}

	/** Add missing Deck stack names as Status options while preserving existing option IDs and values. */
	public function ensureStatusOptions(string $userId, string $statusFieldId, array $fields, array $stackTitles): array {
		$statusField = null;
		foreach ($fields as $field) {
			if (($field['id'] ?? '') === $statusFieldId) {
				$statusField = $field;
				break;
			}
		}
		if ($statusField === null || !isset($statusField['options'])) {
			throw new \RuntimeException('GitHub Project has no editable Status field');
		}
		$options = [];
		$input = [];
		foreach ($statusField['options'] as $option) {
			$options[mb_strtolower($option['name'])] = $option['id'];
			$input[] = [
				'id' => $option['id'], 'name' => $option['name'],
				'color' => $option['color'] ?? 'GRAY', 'description' => $option['description'] ?? '',
			];
		}
		$missing = false;
		foreach ($stackTitles as $title) {
			$title = trim((string)$title);
			if ($title === '' || isset($options[mb_strtolower($title)])) {
				continue;
			}
			$input[] = ['name' => $title, 'color' => 'GRAY', 'description' => 'Nextcloud Deck'];
			$options[mb_strtolower($title)] = '';
			$missing = true;
		}
		if (!$missing) {
			return $options;
		}
		$q = 'mutation($field:ID!,$options:[ProjectV2SingleSelectFieldOptionInput!]){ updateProjectV2Field(input:{fieldId:$field singleSelectOptions:$options}){ projectV2Field{ ... on ProjectV2SingleSelectField{ options{id name} } } } }';
		$res = $this->client->graphql($userId, $q, ['field' => $statusFieldId, 'options' => $input]);
		$updated = $res['data']['updateProjectV2Field']['projectV2Field']['options'] ?? null;
		if (!is_array($updated)) {
			throw new \RuntimeException('GitHub Status options could not be updated');
		}
		$result = [];
		foreach ($updated as $option) {
			$result[mb_strtolower($option['name'])] = $option['id'];
		}
		return $result;
	}

	/** @return array{items: array, hasNext: bool, cursor: ?string} */
	public function listItems(string $userId, string $projectId, ?string $after = null, ?string $filter = null): array {
		$q = 'query($pid:ID!,$after:String,$filter:String){ node(id:$pid){ ... on ProjectV2{
			items(first:50, after:$after, query:$filter){ pageInfo{ hasNextPage endCursor }
			nodes{ id updatedAt
				content{ __typename
					... on DraftIssue{ id title body updatedAt }
					... on Issue{ id number title body state closed updatedAt url repository{nameWithOwner} assignees(first:100){nodes{login} pageInfo{hasNextPage}} labels(first:100){nodes{name} pageInfo{hasNextPage}} }
					... on PullRequest{ id number title body state merged updatedAt url }
				}
				fieldValues(first:100){ nodes{ __typename
					... on ProjectV2ItemFieldSingleSelectValue{ name optionId field{ ... on ProjectV2FieldCommon{ id name } } }
					... on ProjectV2ItemFieldTextValue{ text field{ ... on ProjectV2FieldCommon{ id name } } }
					... on ProjectV2ItemFieldDateValue{ date field{ ... on ProjectV2FieldCommon{ id name } } }
				} }
			} } } } }';
		$res = $this->client->graphql($userId, $q, ['pid' => $projectId, 'after' => $after, 'filter' => $filter ?? '']);
		if (($res['data']['node'] ?? null) === null) {
			$msg = isset($res['errors']) ? (string)json_encode($res['errors']) : 'empty response';
			throw new \RuntimeException('GitHub project query failed: ' . substr($msg, 0, 300));
		}
		$conn = $res['data']['node']['items'] ?? ['nodes' => [], 'pageInfo' => []];
		if (($conn['pageInfo']['hasNextPage'] ?? false) && empty($conn['pageInfo']['endCursor'])) {
			throw new \RuntimeException('GitHub item pagination failed');
		}
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

	/** Convert a Project draft in place so the Project item keeps its Status and dates. */
	public function convertDraftToIssue(string $userId, string $projectId, string $itemId, string $repository): array {
		$repositoryId = $this->getRepositoryNodeId($userId, $repository);
		$q = 'mutation($project:ID!,$item:ID!,$repo:ID!){ convertProjectV2DraftIssueItemToIssue(input:{projectId:$project itemId:$item repositoryId:$repo}){ item{ id updatedAt content{ __typename ... on Issue{ id number title body state closed updatedAt url repository{nameWithOwner} labels(first:100){nodes{name} pageInfo{hasNextPage}} assignees(first:100){nodes{login} pageInfo{hasNextPage}} } } fieldValues(first:100){nodes{ ... on ProjectV2ItemFieldSingleSelectValue{ name optionId field{ ... on ProjectV2FieldCommon{id name} } } ... on ProjectV2ItemFieldDateValue{ date field{ ... on ProjectV2FieldCommon{id name} } } } } } } }';
		$res = $this->client->graphql($userId, $q, ['project' => $projectId, 'item' => $itemId, 'repo' => $repositoryId]);
		$item = $res['data']['convertProjectV2DraftIssueItemToIssue']['item'] ?? null;
		if (!is_array($item) || empty($item['id']) || empty($item['content']['number'])) {
			throw new \RuntimeException('GitHub draft could not be converted to an issue');
		}
		return $item;
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

	public function archiveItem(string $userId, string $projectId, string $itemId, bool $archive = true): void {
		$op = $archive ? 'archiveProjectV2Item' : 'unarchiveProjectV2Item';
		$m = "mutation(\$pid:ID!,\$item:ID!){ $op(input:{projectId:\$pid itemId:\$item}){ item{ id } } }";
		$this->client->graphql($userId, $m, ['pid' => $projectId, 'item' => $itemId]);
	}

	public static function dateOf(array $item, ?string $dateFieldId = ''): ?string {
		foreach ($item['fieldValues']['nodes'] ?? [] as $fv) {
			if (($fv['__typename'] ?? '') !== 'ProjectV2ItemFieldDateValue') {
				continue;
			}
			if ($dateFieldId !== '' && ($fv['field']['id'] ?? '') !== $dateFieldId) {
				continue;
			}
			return $fv['date'] ?? null;
		}
		return null;
	}

	/** Update issue via REST (repo-scoped, robust for title/body/state/labels/assignees). */
	public function updateIssueRest(string $userId, string $repo, int $number, array $fields): void {
		$this->client->rest($userId, 'PATCH', '/repos/' . $repo . '/issues/' . $number, $fields);
	}

	public function setIssueLabels(string $userId, string $repo, int $number, array $labels): void {
		$this->ensureRepositoryLabels($userId, $repo, $labels);
		$this->client->rest($userId, 'PUT', '/repos/' . $repo . '/issues/' . $number . '/labels', ['labels' => array_values($labels)]);
	}

	private function ensureRepositoryLabels(string $userId, string $repo, array $labels): void {
		if ($labels === []) {
			return;
		}
		$known = [];
		$page = 1;
		do {
			$data = $this->client->rest($userId, 'GET', '/repos/' . $repo . '/labels?per_page=100&page=' . $page);
			foreach ($data as $entry) {
				if (isset($entry['name'])) {
					$known[mb_strtolower($entry['name'])] = true;
				}
			}
			$page++;
		} while (count($data) === 100);
		foreach ($labels as $label) {
			$name = trim((string)$label);
			if ($name !== '' && !isset($known[mb_strtolower($name)])) {
				$this->client->rest($userId, 'POST', '/repos/' . $repo . '/labels', ['name' => $name, 'color' => '6f42c1', 'description' => 'Nextcloud Deck']);
				$known[mb_strtolower($name)] = true;
			}
		}
	}

	public function setIssueAssignees(string $userId, string $repo, int $number, array $assignees): void {
		$this->updateIssueRest($userId, $repo, $number, ['assignees' => array_values($assignees)]);
	}

	/** @return array<int, array{id:int,body:string,user:string,created_at:string}> */
	public function getIssueComments(string $userId, string $repo, int $number): array {
		$out = [];
		$page = 1;
		do {
			$data = $this->client->rest($userId, 'GET', '/repos/' . $repo . '/issues/' . $number . '/comments?per_page=100&page=' . $page);
			foreach ($data as $c) {
				if (!isset($c['id'])) {
					continue;
				}
				$out[] = ['id' => (int)$c['id'], 'body' => $c['body'] ?? '', 'user' => $c['user']['login'] ?? '', 'created_at' => $c['created_at'] ?? ''];
			}
			$page++;
		} while (count($data) === 100);
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

	public static function statusOf(array $item, ?string $statusFieldId = null): string {
		foreach ($item['fieldValues']['nodes'] ?? [] as $fv) {
			if (($fv['__typename'] ?? '') !== 'ProjectV2ItemFieldSingleSelectValue') {
				continue;
			}
			if ($statusFieldId !== null && $statusFieldId !== '') {
				if (($fv['field']['id'] ?? '') === $statusFieldId) {
					return $fv['name'] ?? '';
				}
				continue;
			}
			if (($fv['field']['name'] ?? '') === 'Status') {
				return $fv['name'] ?? '';
			}
		}
		return '';
	}
}
