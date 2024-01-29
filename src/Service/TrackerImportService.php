<?php

declare(strict_types=1);

namespace YaPro\IssueTracker\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\Loader\LoaderChain;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ConstraintViolationListNormalizer;
use Symfony\Component\Serializer\Normalizer\DataUriNormalizer;
use Symfony\Component\Serializer\Normalizer\DateIntervalNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeZoneNormalizer;
use Symfony\Component\Serializer\Normalizer\FormErrorNormalizer;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;
use Symfony\Component\Serializer\Normalizer\MimeMessageNormalizer;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Normalizer\UidNormalizer;
use Symfony\Component\Serializer\Normalizer\UnwrappingDenormalizer;
use Throwable;
use YaPro\IssueTracker\DataProvider\JiraDataProvider;
use YaPro\IssueTracker\Entity\EntityInterface;
use YaPro\IssueTracker\Entity\Issue;
use YaPro\IssueTracker\Entity\History;
use YaPro\IssueTracker\Entity\User;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

use function count;
use function lcfirst;

class TrackerImportService
{
    const DEFAULT_USER_ID = 'DEFAULT_USER';
    const TRACKER_HISTORY_FIELD_SPRINT = 'sprint';

    /** @var array <sting, TrackerUser> */
    private array $userList;
    private Serializer $serializer;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JiraDataProvider $dataProvider,
        private readonly Connection $connection
    ) {
        $classMetadataFactory = new ClassMetadataFactory(new AnnotationLoader(new AnnotationReader()));
        $this->serializer = new Serializer([
            new GetSetMethodNormalizer(),
            new DateTimeNormalizer(['datetime_format' => 'Y-m-d H:i:s']),
            new ObjectNormalizer($classMetadataFactory), //, new MetadataAwareNameConverter($classMetadataFactory) new CamelCaseToSnakeCaseNameConverter(
        ]);
        $this->serializer = self::create();
    }

    public static function create(): Serializer
    {
        // https://github.com/symfony/symfony/issues/35554 so we need to implement the
        // https://github.com/symfony/framework-bundle/blob/5.4/Resources/config/serializer.php

        $reflectionExtractor = new ReflectionExtractor();
        $phpDocExtractor = new PhpDocExtractor();

        $LoaderChain = new LoaderChain([]);
        $ClassMetadataFactory = new ClassMetadataFactory($LoaderChain);
        $ClassDiscriminatorFromClassMetadata = new ClassDiscriminatorFromClassMetadata($ClassMetadataFactory);
        $MetadataAwareNameConverter = new MetadataAwareNameConverter($ClassMetadataFactory);
        // $SerializerExtractor = new SerializerExtractor($ClassMetadataFactory);
        $ConstraintViolationListNormalizer = new ConstraintViolationListNormalizer([], $MetadataAwareNameConverter);
        $PropertyInfoExtractor = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$phpDocExtractor, $reflectionExtractor],
            [$phpDocExtractor],
            [$reflectionExtractor],
            [$reflectionExtractor]
        );
        $PropertyNormalizer = new PropertyNormalizer($ClassMetadataFactory, $MetadataAwareNameConverter, $PropertyInfoExtractor, $ClassDiscriminatorFromClassMetadata);
        $PropertyAccessor = new PropertyAccessor();

        $encoders = [new XmlEncoder(), new JsonEncoder()];
        $normalizers = [
            new ArrayDenormalizer(),
            $ConstraintViolationListNormalizer,
            new DataUriNormalizer(),
            new DateIntervalNormalizer(),
            new DateTimeNormalizer(),
            new DateTimeZoneNormalizer(),
            new FormErrorNormalizer(),
            new JsonSerializableNormalizer(null, null),
            //new MimeMessageNormalizer($PropertyNormalizer),
            new ObjectNormalizer($ClassMetadataFactory, $MetadataAwareNameConverter, $PropertyAccessor, $PropertyInfoExtractor, $ClassDiscriminatorFromClassMetadata),
            new ProblemNormalizer(true),
            $PropertyNormalizer,
            new UidNormalizer(),
            new UnwrappingDenormalizer($PropertyAccessor),
        ];

        return new Serializer($normalizers, $encoders);
    }

    /**
     * {@inheritdoc}
     */
    public function denormalize(string $propertyName): string
    {
        $camelCasedName = preg_replace_callback('/(^|_|\.)+(.)/', function ($match) {
            return ('.' === $match[1] ? '_' : '').strtoupper($match[2]);
        }, $propertyName);

        return lcfirst($camelCasedName);
    }

    public function toSnakeCase(array $rows): array
    {
        $result = [];
        foreach ($rows as $field => $value) {
            $result[$this->denormalize($field)] = $value;
        }

        return $result;
    }

    public function findUser(string $id): ?User
    {
        $user = $this->connection->fetchAssociative('SELECT * FROM it_user WHERE id = ?', [$id]);

        return $user === false ? null : $this->serializer->denormalize($this->toSnakeCase($user), User::class);
    }

    public function findIssue(string $id): ?Issue
    {
        $issue = $this->connection->fetchAssociative('SELECT * FROM it_issue WHERE id = ?', [$id]);

        return $issue === false ? null : $this->serializer->denormalize($this->toSnakeCase($issue), Issue::class);
    }

    public function runImport(): void
    {
        foreach ($this->dataProvider->getIssues() as $issueData) {
            try {

                if ($issue = $this->upsertIssue($issueData)){
                    // удаляем все записи по задаче, потому что например Jira позволяет удалять свои же списания времени:
                    $this->connection->delete('it_history', ['issue_id' => $issue->getId()]);
                    $this->addIssueSprint($issue, $issueData);
                    $this->updateLoggedTime($issue);
                    $this->addHistoryIssue($issue);
                }
            } catch (Throwable $exception) {
                $this->logger->error('Issue has a problem', [
                    $exception,
                    'issueData' => $issueData,
                ]);
            }
        }

        // отмечаем задачи спринта от всех остальных:
        $this->updateIsCurrentSprint(false);
        $this->updateIsCurrentSprint(true, $this->dataProvider->getCurrentSprintIssues());
    }

    public function updateIsCurrentSprint(bool $value, array $keyIds = []): void
    {
        $builder = $this->connection->createQueryBuilder()
            ->update('it_issue')
            ->set('is_current_sprint', ':is_current_sprint')
            ->setParameter('is_current_sprint', $value === true ? 1 : 0)
        ;

        if (!empty($keyIds)) {
            $builder->where('id IN (:ids)')->setParameter('ids', $keyIds, Connection::PARAM_STR_ARRAY);
        }

        $builder->executeStatement();
    }

    public function updateLoggedTime(Issue $issue): void
    {
        $workLogs = $this->dataProvider->getIssueWorkLogs($issue->getId());
        if (count($workLogs) < 1) {
            return;
        }
        foreach ($workLogs as $workLog) {
            $this->addIssueWorkLog($issue, $workLog);
        }
        $issue->setDeveloperLogged($this->getLoggedTime($workLogs, User::ROLE_DEVELOPER));
        $issue->setTesterLogged($this->getLoggedTime($workLogs, User::ROLE_TESTER));
        $this->upsert($issue);
    }

    public function addIssueWorkLog(Issue $issue, array $workLog): void
    {
        $history = (new History())
            ->setId($workLog['id'])
            ->setUser($this->upsertUser($workLog['author']))
            ->setIssue($issue)
            ->setFieldName(JiraDataProvider::FIELD_HISTORY_SPENT_TIME)
            ->setFieldValue((string)$workLog['timeSpentSeconds'])
            ->setCreatedAt($this->getDate($workLog['started']));
        $this->upsert($history);
    }

    public function getLoggedTime(array $workLogs, int $roleId): int
    {
        $result = 0;
        foreach ($workLogs as $workLog) {
            $user = $this->upsertUser($workLog['author']);
            if ($user->getRoleId() === $roleId) {
                $result += $workLog['timeSpentSeconds'];
            }
        }

        return $result;
    }

    public function upsertIssue(array $issueData): ?Issue
    {
        echo 'ISSUE: '. $issueData['id'] . ' = ' . $issueData['key'] . PHP_EOL;
        $issue = $this->findIssue($issueData['key']) ?? new Issue();
        $issue->setSummary($issueData['fields']['summary']);
        $issue->setCreatedAt($this->getDate($issueData['fields']['created']));
        $issue->setUpdatedAt($this->getDate($issueData['fields']['updated']));
        $issue->setDeveloper($this->upsertUser($issueData['fields']['assignee']));
        $issue->setStatusName($issueData['fields']['status']['name']);
        $issue->setEpicId($issueData['fields'][JiraDataProvider::FIELD_EPIC_ISSUE_ID] ?? '');

        if (count($issueData['fields']['components']) > 1) {
            $this->logger->notice('The -components- field contains more than one value', $issueData);
        }
        if (count($issueData['fields']['fixVersions']) > 1) {
            $this->logger->notice('The -repository- field contains more than one value', $issueData);
        }
        $issue->setComponentName($issueData['fields']['components'][0]['name'] ?? '');
        $issue->setRepositoryName($issueData['fields']['fixVersions'][0]['name'] ?? '');
        // поле Тестер может быть не заполнено у задач эпика "Активности в команде"
        if (is_array($issueData['fields'][JiraDataProvider::FIELD_TESTER])) {
            $issue->setTester($this->upsertUser($issueData['fields'][JiraDataProvider::FIELD_TESTER]));
        } else {
            //$this->logger->warning('The -tester- field must be specified', $issueData);
            $issue->setTester((new User())->setId(self::DEFAULT_USER_ID));
        }
        $developerEstimations = $this->getRoleEstimations('Developers', $issueData);
        $testerEstimations = $this->getRoleEstimations('Testers', $issueData);
        // если неизвестная задача ИЛИ задача в статусе OPEN, то мы можем менять ей первоначальную оценку
        if (empty($issue->getId()) || $issue->getStatusName() === 'OPEN') {
            $issue->setDeveloperEstimated($this->getEstimated($developerEstimations));
            $issue->setTesterEstimated($this->getEstimated($testerEstimations));
            // если в задаче плагин "оценки по ролям" не включен:
            if ($this->isRolesEstimationsDisabled($issueData)) {
                $issue->setDeveloperEstimated($issueData['fields'][JiraDataProvider::FIELD_ESTIMATED_TIME]);
            }
        }
        $issue->setDeveloperRemaining($this->getRemaining($developerEstimations));
        $issue->setTesterRemaining($this->getRemaining($testerEstimations));
        // если плагин "оценки по ролям" отключен, то значения пустые, значит берем Remaining из стандартного значения:
        if ($issue->getDeveloperRemaining() === 0 && $issue->getTesterRemaining() === 0) {
            $issue->setDeveloperRemaining((int) $issueData['fields'][JiraDataProvider::FIELD_REMAINING_TIME]);
        }

        // шаг нужно выполнять позже upsertUser, т.к. persist($user) должен выполняться раньше чем persist($issue)
        if (empty($issue->getId())) {
            $issue->setId($issueData['key']);
        }
        $this->upsert($issue);

        return $issue;
    }

    public function getDate($date): DateTimeImmutable
    {
        // преобразование из 2023-12-15T12:27:52.000+0300
        return DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vO', $date);
    }

    public function isRolesEstimationsDisabled(array $issueData): bool
    {
        return empty($issueData['fields'][JiraDataProvider::FIELD_ESTIMATIONS_BY_ROLES]);
    }

    public function getRoleEstimations(string $role, array $issueData): string
    {
        // если у задачи нет оценки (такое может быть, когда в задаче не используется оценка по ролям):
        if ($this->isRolesEstimationsDisabled($issueData)) {
            return '';
        }
        /** @var string[] $roles */
        // ["Role: Developers (57600(16h) | 57600(16h))", "Role: Testers (10800(3h) | 10800(3h))", "Role: Others (null | null)"]
        $roles = $issueData['fields'][JiraDataProvider::FIELD_ESTIMATIONS_BY_ROLES];

        // пример строки в переменной $estimate: "Role: Developers (57600(16h) | 57600(16h))",
        foreach ($roles as $estimate) {
            if (str_contains($estimate, $role)) {
                return $estimate;
            }
        }

        return '';
    }

    public function getEstimated(string $roleEstimate): int
    {
        if (trim($roleEstimate) === '') {
            return 0;
        }
        // берем первое значение из строки: "Role: Developers (57600(16h) | 57600(16h))"
        $right = explode('(', $roleEstimate)[1];
        $left = trim(explode('|', $right)[0]);
        return $left === 'null' ? 0 : (int) $left;
    }

    public function getRemaining(string $roleEstimate): int
    {
        if (trim($roleEstimate) === '') {
            return 0;
        }
        // берем второе значение из строки: "Role: Developers (57600(16h) | 57600(16h))"
        $right = explode('|', $roleEstimate)[1];
        $left = trim(explode('(', $right)[0]);
        return $left === 'null' ? 0 : (int) $left;
    }

    public function upsertUser(array $userData): User
    {
        $userKey = $userData['key'];
        if (!isset($this->userList[$userKey])) {
            $this->userList[$userKey] = $this->findUser($userKey);
            if (!isset($this->userList[$userKey])) {
                $this->userList[$userKey] = new User();
                $this->userList[$userKey]->setId($userKey);
                $this->userList[$userKey]->setIsEnabled((bool)$userData['active']);
            }
            // актуализируем данные о пользователе
            $this->userList[$userKey]->setName($userData['displayName']);
            $this->upsert($this->userList[$userKey]);
        }
        return $this->userList[$userKey];
    }

    public function upsert(EntityInterface $entity): void
    {
        $this->upsertRow($entity->getTableName(), $entity->getData(), ['id' => $entity->getId()]);
        /* @todo:
        INSERT INTO inventory (id, name, price, quantity)
        VALUES (1, 'A', 16.99, 120)
        ON CONFLICT(id)
        DO UPDATE SET
          price = EXCLUDED.price,
          quantity = EXCLUDED.quantity;
         */
    }

    function upsertRow($table, array $data, array $criteria, array $types = []): void
    {
        $affectedRows = $this->connection->update($table, $data, $criteria, $types);
        if (0 === $affectedRows) {
            // Row does not exist, create it.
            try {
                $this->connection->insert($table, $data, $types);
            } catch (UniqueConstraintViolationException $e) {
                // Concurrent update
                $this->connection->update($table, $data, $criteria, $types);
            }
        }
    }

    public function isIssueExistAndActual(Issue $issue, array $issueData): bool
    {
        if (empty($issue->getId())) {
            return false;
        }
        $format = 'Y.m.d_h.i.s';

        return $issue->getUpdatedAt()->format($format) !== $this->getDate($issueData['fields']['updated'])->format($format);
    }

    public function addHistoryIssue(Issue $issue): void
    {
        $data = $this->dataProvider->getHistory($issue->getId());

        foreach ($data['changelog']['histories'] as $action) {
            foreach ($action['items'] as $item) {
                $this->addHistoryIssueStatus($issue, $action['author'], $action['created'], $item);
            }
        }
        $this->addHistoryIssueSprint($issue, $data['changelog']['histories']);
    }

    public function addHistoryIssueSprint(Issue $issue, array $histories): void
    {
        $sprintsAsString = '';
        $sprintsAsStringAuthor = [];
        $sprintsAsStringCreated = '';
        foreach ($histories as $action) {
            foreach ($action['items'] as $item) {
                if ($item['field'] === "Sprint") {
                    // последняя запись самая верная, получаем названия спринтов из нее
                    $sprintsAsString = $item['toString'];
                    $sprintsAsStringAuthor = $action['author'];
                    $sprintsAsStringCreated = $action['created'];
                }
            }
        }
        $sprints = $this->getHistoryIssueSprints(
            $issue,
            $sprintsAsStringAuthor,
            $sprintsAsStringCreated,
            explode(', ', $sprintsAsString)
        );
        foreach ($sprints as $sprint) {
            $this->upsert($sprint);
        }
    }

    public function getHistoryIssueSprints(Issue $issue, array $author, string $created, array $sprints): array
    {
        $result = [];
        foreach ($sprints as $sprint) {
            if (!empty($sprint)) {
                $result[] = (new History())
                    ->setUser($this->upsertUser($author))
                    ->setIssue($issue)
                    ->setFieldName(self::TRACKER_HISTORY_FIELD_SPRINT)
                    ->setFieldValue($this->getIssueHistorySprint($sprint))
                    ->setCreatedAt($this->getDate($created));
            }
        }
        return $result;
    }

    public function getIssueHistorySprint(?string $sprintsNames): string
    {
        if (!is_string($sprintsNames)) {
            return '';
        }
        $sprints = explode(',', $sprintsNames);
        return trim(end($sprints));
    }

    public function addHistoryIssueStatus(Issue $issue, array $author, string $created, array $item): void
    {
        $field = $item['field'];
        if ($field !== JiraDataProvider::FIELD_HISTORY_STATUS) {
            return;
        }
        $history = (new History())
            ->setUser($this->upsertUser($author))
            ->setIssue($issue)
            ->setFieldName($field)
            ->setFieldValue((string) $item['toString'])
            ->setCreatedAt($this->getDate($created))
        ;
        $this->upsert($history);
    }

    public function addIssueSprint(Issue $issue, mixed $issueData): void
    {
        $sprintInfo = trim($issueData['fields'][JiraDataProvider::FIELD_ISSUE_SPRINT_INFO][0] ?? '');
        if (empty($sprintInfo)) {
            return;
        }
        $history = (new History())
            ->setUser($issue->getDeveloper())
            ->setIssue($issue)
            ->setFieldName(self::TRACKER_HISTORY_FIELD_SPRINT)
            ->setFieldValue($this->getIssueSprint($sprintInfo))
            ->setCreatedAt($issue->getCreatedAt())
        ;
        $this->upsert($history);
    }

    public function getIssueSprint(string $sprintInfo): string
    {
        return trim(explode(',', explode(',name=', $sprintInfo)[1])[0]);
    }

    public function getEpicKeyId(array $issueLinks): string
    {
        foreach ($issueLinks as $issueLink) {
            if (isset($issueLink['outwardIssue']['key'])) {
                return $issueLink['outwardIssue']['key'];
            }
        }

        return '';
    }
}
