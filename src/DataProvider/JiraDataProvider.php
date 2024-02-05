<?php

declare(strict_types=1);

namespace YaPro\IssueTracker\DataProvider;

use Symfony\Component\HttpClient\Response\TraceableResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use YaPro\Helper\JsonHelper;

/**
 * API:
 * https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-issue-search/#api-rest-api-2-search-get
 * https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-issues/#api-rest-api-2-issue-issueidorkey-changelog-get
 * Оптимизация:
 * https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-issue-worklogs/#api-rest-api-2-worklog-updated-get
 * https://developer.atlassian.com/cloud/jira/platform/rest/v2/intro/#expansion
 */
class JiraDataProvider implements DataProviderInterface
{
    // Полезные поля для каждой здаче при выгрузке из /rest/api/2/search:
    /**
     * project
     * labels
     * creator
     * reporter
     * issuetype
     * description
     * aggregateprogress - поле похоже на progress (описание см. ниже)
     * progress:
     *    total - сколько всего запланировано времени на задачу (разработка + тестирование)
     *    progress - ?
     *    percent -?
     */
    public const FIELD_TESTER = 'customfield_10131'; // информация о тестировщике
    public const FIELD_EPIC_ISSUE_ID = 'customfield_10933';
    public const TEAM_ACTIVITY_EPIC_ID = 'SS-1783'; // ID задачи эпика Активностей в нашей команде
    public const FIELD_ISSUE_SPRINT_INFO = 'customfield_10330';
    public const FIELD_ESTIMATIONS_BY_ROLES = 'customfield_14946'; // оценка по ролям
    /*
       "customfield_14946": [
            "Role: Developers (57600(16h) | 28800(8h))", - запланировано / осатлось
            "Role: Testers (7200(2h) | 7200(2h))",
            "Role: Others (null | null)"
        ],

    */

    // Estimated - количество секунд запланированных на разработку (аналогичное значение хранится в поле
    // aggregatetimeoriginalestimate, я пробовал менять Estimated в задаче, значение меняется в обоих полях)
    public const FIELD_ESTIMATED_TIME = 'timeoriginalestimate';

    // Remaining (remaining time) - количество секунд оставшихся на разработку (аналогичное значение хранится в поле
    // aggregatetimeestimate, я пробовал менять Remaining в задаче, значение меняется в обоих полях)
    public const FIELD_REMAINING_TIME = 'timeestimate';

    //public const FIELD_LOGGED_BY_DEVELOPER = 'customfield_14948'; // сколько времени залогировал разработчик
    public const FIELD_HISTORY_STATUS = 'status'; // статус задачи

    public const FIELD_HISTORY_SPENT_TIME = 'timespent'; // секунд списано - брать из https://jira.yapro.ru/rest/api/2/issue/SS-2046/worklog
    /*
    {
    "startAt": 0,
    "maxResults": 1,
    "total": 1,
    "worklogs": [
        {
            "self": "https://jira.yapro.ru/rest/api/2/issue/1242181/worklog/637257",
            "author": {
                "self": "https://jira.yapro.ru/rest/api/2/user?username=lebedenko",
                "name": "lebedenko",
                "key": "lebedenko",
                "emailAddress": "lebedenko@site.ru",
                "avatarUrls": {
                    "48x48": "https://jira.yapro.ru/secure/useravatar?ownerId=lebedenko&avatarId=22376",
                    "24x24": "https://jira.yapro.ru/secure/useravatar?size=small&ownerId=lebedenko&avatarId=22376",
                    "16x16": "https://jira.yapro.ru/secure/useravatar?size=xsmall&ownerId=lebedenko&avatarId=22376",
                    "32x32": "https://jira.yapro.ru/secure/useravatar?size=medium&ownerId=lebedenko&avatarId=22376"
                },
                "displayName": "Лебеденко Николай",
                "active": true,
                "timeZone": "Etc/GMT-3"
            },
            "updateAuthor": {
                "self": "https://jira.yapro.ru/rest/api/2/user?username=lebedenko",
                "name": "lebedenko",
                "key": "lebedenko",
                "emailAddress": "lebedenko@site.ru",
                "avatarUrls": {
                    "48x48": "https://jira.yapro.ru/secure/useravatar?ownerId=lebedenko&avatarId=22376",
                    "24x24": "https://jira.yapro.ru/secure/useravatar?size=small&ownerId=lebedenko&avatarId=22376",
                    "16x16": "https://jira.yapro.ru/secure/useravatar?size=xsmall&ownerId=lebedenko&avatarId=22376",
                    "32x32": "https://jira.yapro.ru/secure/useravatar?size=medium&ownerId=lebedenko&avatarId=22376"
                },
                "displayName": "Лебеденко Николай",
                "active": true,
                "timeZone": "Etc/GMT-3"
            },
            "comment": "",
            "created": "2023-12-23T09:12:15.817+0300",
            "updated": "2023-12-23T09:12:15.817+0300",
            "started": "2023-12-23T09:10:00.000+0300",
            "timeSpent": "30m",
            "timeSpentSeconds": 1800,
            "id": "637257",
            "issueId": "1242181"
        }
    ]
    }
    */
    public const FIELD_ISSUE_LINKS = 'issuelinks'; // связанные задачи:

    /*
     "issuelinks": [
        {
            "id": "1005164",
            "self": "https://jira.yapro.ru/rest/api/2/issueLink/1005164",
            "type": {
                "id": "10310",
                "name": "Mention",
                "inward": "is mentioned by",
                "outward": "mentions",
                "self": "https://jira.yapro.ru/rest/api/2/issueLinkType/10310"
            },
            "outwardIssue": {
                "id": "1069098",
                "key": "SS-1783",
     */

    public function __construct(
        private readonly JsonHelper $jsonHelper,
        private readonly HttpClientInterface $client,
        private readonly string $token,
    ) {
    }

    protected function send(string $url, array $parameters = []): TraceableResponse
    {
        $path = $url . ($parameters ? '?' . http_build_query($parameters) : '');
        return $this->client->request('GET', $path, [
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
        ]);
    }

    public function getIssues(): array
    {
        $result = [];
        do {
            $data = $this->getIssuesBatch(count($result));
            $result = array_merge($result, $data['issues']);
        } while (count($result) < $data['total']);

        return $result;
    }

    public function getCurrentSprintIssues(): array
    {
        $response = $this->send('/rest/api/2/search', [
            'jql' => 'project = SS AND sprint in openSprints ()',
            'fields' => 'summary',
        ]);
        return array_map(function ($item) {
            return $item->key;
        }, $this->jsonHelper->jsonDecode($response->getContent())->issues);
    }

    public function getIssuesBatch(int $startAt = 0): array
    {
        // все задачи в спринтах SEO_* имеют заполненные поля оценки по ролям, кроме ниже перечисленных:
        $wrongIssues = 'SS-1873,SS-1892,SS-1861,SS-1853,SS-1810,SS-1752,SS-1750,SS-1749,SS-1716,SS-1677';

        $response = $this->send('/rest/api/2/search', [
            'startAt' => $startAt,
            // idea: sprint not in opensprints() AND resolutiondate <= startofweek() AND resolutiondate > startofweek(-2w)
            // status CHANGED FROM "Open" TO "In Progress" AFTER -1w
            // project = "Project" and status changed during (2018-08-01, 2018-08-30) FROM ("Status A") TO ("Status B")
            // нам приходится синкать задачи всех спринтов, потому что точно не знаем, когда задача переходит в Closed
            // 'jql' => 'project = SS AND sprint = "SEO_" AND issue not in ('.$wrongIssues. ') OR issue in childIssuesOf("' . self::TEAM_ACTIVITY_EPIC_ID . '")',
            'jql' => 'project = SS AND ( sprint = "SEO_" OR issue in childIssuesOf("' . self::TEAM_ACTIVITY_EPIC_ID . '") )',
            //'jql' => 'issue = SS-1788', // одна из активностей в команде
            //'jql' => 'issue = SS-2094',
            'fields' => implode(',', [
                'summary',
                'status',
                'created',
                'updated',
                'components',
                // Для какого компонента выполняется задача
                'fixVersions',
                // Для какого веб-сервиса - указываем репозитории (если правка библиотеки, то указывается не библиотека, а репозиторий в котором обновляется библиотека)
                'assignee',
                'progress',
                self::FIELD_TESTER,
                self::FIELD_ESTIMATIONS_BY_ROLES,
                self::FIELD_EPIC_ISSUE_ID,
                self::FIELD_ISSUE_SPRINT_INFO,
                self::FIELD_ESTIMATED_TIME,
                self::FIELD_REMAINING_TIME,
            ]),
        ]);

        return $this->jsonHelper->jsonDecode($response->getContent(), true);
    }
    /*
     Когда список задач получаю, там есть такое поле:
        "customfield_10330": [
            "com.atlassian.greenhopper.service.sprint.Sprint@396feb4c[id=3114,rapidViewId=307,state=ACTIVE,name=SEO_7,startDate=2023-12-18T14:27:00.000+03:00,endDate=2024-01-29T14:27:00.000+03:00,completeDate=<null>,activatedDate=2023-12-17T16:25:10.970+03:00,sequence=3114,goal=,autoStartStop=false,synced=false]"
        ],
     Когда в истории определенной задачи смотрю, то там есть:
        "items": [
            {
                "field": "Sprint",
                "fieldtype": "custom",
                "from": "2781",
                "fromString": "$EO Cпринт - #36",
                "to": "2781, 2807",
                "toString": "$EO Cпринт - #36, $EO Cпринт - #37 (JUNE)"
            }
        ]
    // https://docs.adaptavist.com/sr4js/8.15.0/features/jql-functions/included-jql-functions/sprint#nextSprint
     */

    /*
     * можно было бы смотреть списание времени в history, но там не пишется started (а именно в этом поле хранится дата
     * времени указывающая на какую дату выполнено списание, created для этого не подходит)
     */
    public function getIssueWorkLogs(string $issueKey): array
    {
        $response = $this->send('/rest/api/2/issue/' . $issueKey . '/worklog');

        return $this->jsonHelper->jsonDecode($response->getContent(), true)['worklogs'];
    }

    /*
     * ВАЖНО: к сожалению, поле timespent выдает временами неверные данные, особенно все запутывается, когда
     * пользователь изменяет или удаляет данные. Используйте getIssueWorkLogs()
     */
    public function getHistory(string $issueKey): array
    {
        // нам нужна история изменения статусов задачи, а не только списание времени /rest/api/2/issue/SS-2000/worklog
        $response = $this->send('/rest/api/latest/issue/' . $issueKey, [
            'fields' => 'aggregatetimespent',
            'expand' => 'changelog'
        ]);

        return $this->jsonHelper->jsonDecode($response->getContent(), true);
    }
}
