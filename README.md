
## Installation

Add as a requirement in your `composer.json` file or run for prod:
```sh
composer require yapro/apiration-bundle
```

As dev:
```sh
composer require yapro/apiration-bundle dev-master
```

## Configuration - step 1 - database

Add the next tables with data:
```sql
CREATE TABLE it_issue (
    id VARCHAR(255) PRIMARY KEY NOT NULL,
    epic_id VARCHAR(255),
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    is_current_sprint BOOLEAN DEFAULT false NOT NULL,
    status_name VARCHAR(255) NOT NULL,
    status_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    summary VARCHAR(255) NOT NULL,
    developer_id VARCHAR(255) NOT NULL,
    developer_estimated INT NOT NULL,
    developer_remaining INT NOT NULL,
    developer_logged INT NOT NULL,
    tester_id VARCHAR(255) NOT NULL,
    tester_estimated INT NOT NULL,
    tester_remaining INT NOT NULL,
    tester_logged INT NOT NULL,
    component_name VARCHAR(255),
    repository_name VARCHAR(255)
);
CREATE INDEX in_it_issue$developer_id ON it_issue (developer_id);
CREATE INDEX in_it_issue$tester_id ON it_issue (tester_id);

CREATE TABLE it_history (
    id VARCHAR(255) PRIMARY KEY NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    issue_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    field_name VARCHAR(255) NOT NULL,
    field_value VARCHAR(255) NOT NULL
);
CREATE INDEX in_it_history$user_id ON it_history (user_id);
CREATE INDEX in_it_history$issue_id ON it_history (issue_id);

CREATE TABLE it_user (
    id VARCHAR(255) PRIMARY KEY NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_enabled BOOLEAN NOT NULL,
    role_id SMALLINT NOT NULL
);

-- CONSTRAINTS:
ALTER TABLE it_issue ADD CONSTRAINT fk_it_issue$developer_id__it_user$id FOREIGN KEY (developer_id) REFERENCES it_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE it_issue ADD CONSTRAINT fk_it_issue$tester_id__it_user$id FOREIGN KEY (tester_id) REFERENCES it_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE it_history ADD CONSTRAINT fk_it_history$user_id__it_user$id FOREIGN KEY (user_id) REFERENCES it_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE it_history ADD CONSTRAINT fk_it_history$issue_id__it_issue$id FOREIGN KEY (issue_id) REFERENCES it_issue (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE;

-- DATA:
INSERT INTO it_user (id, name, is_enabled, role_id) VALUES ('DEFAULT_USER', 'DEFAULT_USER', true, 0);
```

## Configuration - step 2 - symfony

Add the jira.http_client to file config/framework.yaml
```yaml
framework:
  http_client:
    scoped_clients:
      jira.http_client:
        base_uri: 'https://jira.site.ru'
```

## Configuration - step 3 - env variable

Add the next variable to file .env:
```yaml
JIRA_TOKEN=XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

## ВАЖНО: если исправили ошибку, то удалите данные, т.к. в коде основанная на существование данных:
```sql
truncate table public.it_issue cascade;
```

### Сжигание бизнес времени (анализ по закрытым задачам): плюс в diff означает перерасход
Данный запрос показывает информацию о том, кто:
- переоценил (кто списал меньше, чем запланировал)
- недооценил (кто списал больше, чем запланировал)
```sql
-- списание по каждому человеку:
--- задачи и последняя дата списания времени:
WITH issues_last_timespent_at AS (
    SELECT issue_id, MAX(created_at) AS last_timespent_at
    FROM it_history
    WHERE field_name = 'timespent'
    GROUP BY issue_id
--- задачи по которым завершены работы в указанный промежуток времени:
), issues AS (
    SELECT ti.*
    FROM it_issue ti
    JOIN issues_last_timespent_at ilta ON ilta.issue_id = ti.id
    WHERE ti.status_name='Закрыто' AND ilta.last_timespent_at BETWEEN '2023-10-18T00:00:00' AND '2024-01-16T23:59:59'
    -- однажды, разработчик списал только 1 час за указанный период времени, потому что заболел и проболел 2 недели, 
    -- позже разработчик вышел и доделал задачу, но из-за этого коэффициент разработчика увеличился до 25, что конечно 
    -- не правильно, поэтому учитываем дату времени закрытия задачи - она должна совпадать с выше указанной датой:
    AND ti.status_updated_at < '2024-01-16T23:59:59'
--- задачи и разница трудозатрат по времени между ожиданием и реальностью:
), users_issues_time_diff AS (
---- по разработчикам:
    SELECT
        developer_id AS user_id,
        id,
        developer_estimated AS estimated,
        developer_logged AS logged,
        developer_logged - developer_estimated AS time_diff
    FROM issues
    UNION
---- по тестировщикам:
    SELECT
        tester_id AS user_id,
        id,
        tester_estimated AS estimated,
        tester_logged AS logged,
        tester_logged - tester_estimated AS time_diff
    FROM issues
)
SELECT
    tu.name,
    CONCAT(round(SUM(uitd.estimated::numeric)/60/60, 2), ' h') AS estimated,
    CONCAT(round(SUM(uitd.logged::numeric)/60/60, 2), ' h') AS logged,
    CONCAT(round(SUM(uitd.time_diff::numeric)/60/60, 2), ' h') AS diff,
    CONCAT((SUM(uitd.logged) * 100) / SUM(uitd.estimated), ' %') AS logged_percentage, -- расход времени
    CONCAT(((SUM(uitd.logged) * 100) / SUM(uitd.estimated)) - 100, ' %') AS overruns_percentage, -- процентный перерасход
    round(100::numeric/((SUM(uitd.logged) * 100) / SUM(uitd.estimated)), 2) AS coefficient
FROM users_issues_time_diff uitd
JOIN it_user tu ON tu.id = uitd.user_id
WHERE uitd.estimated > 0 AND uitd.logged > 0
GROUP BY 1
ORDER BY 7 DESC;
```
для дебага заменяем последний SELECT на:
```sql
SELECT
    tu.name,
    uitd.id,
    uitd.estimated,
    uitd.logged,
    uitd.time_diff,
    ((uitd.logged * 100) / uitd.estimated) AS time_percentage,
    ((uitd.logged * 100) / uitd.estimated) - 100 AS percentage_overruns
FROM users_issues_time_diff uitd
JOIN it_user tu ON tu.id = uitd.user_id
WHERE uitd.estimated > 0 AND uitd.logged > 0
AND name='User Name';
```
p.s. немного изменив данный запрос, можно создать график, показывающий количество задач с превышением списанного 
времени (по каждому человеку) за указанный промежуток времени.

### Задачи без изменения статуса (например за сутки)
```sql
-- находим задачи, у которых было изменение статуса за указанный диапазон времени (например за сутки)
WITH statuses_yesterday AS (
    SELECT issue_id
    FROM it_history
    WHERE field_name = 'status' AND created_at BETWEEN '2023-12-17T10:00:00.242Z' AND '2023-12-18T10:00:00.242Z'
    GROUP BY issue_id
)
SELECT
    ti.id,
    ti.status_name AS status,
    tud.name AS developer,
    tut.name AS tester,
    ti.summary
FROM it_issue ti
LEFT JOIN statuses_yesterday sy on sy.issue_id = ti.id
INNER JOIN public.it_user tud on tud.id = ti.developer_id
INNER JOIN public.it_user tut on tut.id = ti.tester_id
WHERE ti.status_name NOT IN ('Ready for Development', 'Закрыто') AND ti.is_open_sprint_issue=true AND sy.issue_id IS NULL;
```

### Списано на исследование каждым человеком за указанный промежуток времени
```sql
SELECT
    tu.name user_name,
    sum(tih.field_value::NUMERIC/60/60) sum_hours
FROM it_history tih
JOIN it_user tu ON tu.id = tih.user_id
WHERE
  tih.created_at BETWEEN '2023-12-18T00:00:00Z' AND '2023-12-31T23:59:59Z'
  AND tih.field_name = 'timespent'
  AND tih.field_value <> ''
  AND tih.issue_id = 'SS-1820' -- Исследования и тесткейсы : задача SS-1820
GROUP BY tu.name
ORDER BY 1;
```
p.s. к сожалению, сделать такой отчет по текущему спринту нельзя, потому что задача Исследование не привязана к спринту.

### Списано на активности каждым человеком за указанный промежуток времени

В запросе указан эпик активностей - SS-1783:
```sql
SELECT
    tu.name user_name,
    sum(tih.field_value::NUMERIC/60/60) sum_hours
FROM it_history tih
JOIN it_user tu ON tu.id = tih.user_id
JOIN it_issue ti ON ti.id = tih.issue_id AND ti.epic_id='SS-1783'
WHERE
    tih.created_at BETWEEN '2023-12-18T00:00:00Z' AND '2023-12-31T23:59:59Z'
  AND tih.field_name = 'timespent'
  AND tih.field_value <> ''
GROUP BY tu.name
ORDER BY 2 DESC;
```
p.s. к сожалению, сделать такой отчет по текущему спринту нельзя, потому что задачи Активностей не привязаны к спринту.

### Списано на активности и задачи в команде по каждому человеку за указанный промежуток времени

В запросе указан эпик активностей - SS-1783:
```sql
-- 
WITH user_activity_seconds AS (
    SELECT
        tu.name user_name,
        sum(tih.field_value::NUMERIC) all_seconds,
        sum(CASE WHEN ti.epic_id='SS-1783' THEN tih.field_value::NUMERIC ELSE 0 END) AS activity_seconds
    FROM it_history tih
    JOIN it_user tu ON tu.id = tih.user_id
    JOIN it_issue ti ON ti.id = tih.issue_id
    WHERE
        tih.created_at BETWEEN '2023-12-18T00:00:00Z' AND '2023-12-31T23:59:59Z'
      AND tih.field_name = 'timespent'
      AND tih.field_value <> ''
    GROUP BY tu.name
), user_activity AS (
    SELECT
        user_name,
        all_seconds,
        activity_seconds,
        all_seconds - activity_seconds AS issues_seconds
    FROM user_activity_seconds
)
SELECT
    user_name,
    all_seconds,
    round(all_seconds/60/60, 2) all_hours,
    round(issues_seconds/60/60, 2) AS issues_hours,
    CONCAT(round((issues_seconds * 100) / all_seconds, 2), ' %') AS issues_percentage,
    round(activity_seconds/60/60, 2) AS activity_hours,
    CONCAT(round((COALESCE(NULLIF(activity_seconds, 0), 1) * 100) / COALESCE(NULLIF(all_seconds, 0), 1), 2), ' %') AS activity_percentage
FROM user_activity
ORDER BY 6 DESC;
```
p.s. к сожалению, сделать такой отчет по текущему спринту нельзя, потому что задачи Активностей не привязаны к спринту.

### График: Списано на задачи и активности по каждому человеку за указанный промежуток времени
```sql
SELECT
  floor(extract(epoch from tih.created_at)/86400)*86400 AS "time", -- $__timeGroupAlias(tih.created_at, $__interval, previous),
  tu.name AS "metric",
  sum(tih.field_value::NUMERIC/60/60) OVER (Partition by tih.user_id ORDER BY tih.created_at) AS "value"
FROM it_history tih
JOIN it_user tu ON tu.id = tih.user_id
WHERE
  tih.field_name = 'timespent' AND 
  tih.created_at BETWEEN '2023-12-18T00:00:00' AND '2023-12-31T23:59:59' -- BETWEEN '${__from:date:YYYY-MM-DD}T00:00:00' AND '${__to:date:YYYY-MM-DD}T23:59:59'
ORDER BY tih.created_at
```

### Суммарное количество задач указанного спринта и планируемое количество часов по ролям

```sql
-- 
WITH issues_next_sprint AS (
    SELECT *
    FROM it_issue
    WHERE id IN (SELECT DISTINCT issue_id
                 FROM it_history
                 WHERE field_name = 'sprint' AND field_value = 'SEO_8')
      AND status_name != 'Closed'
    ORDER BY id -- status_name
)
SELECT
    COUNT(*) issues,
    SUM(developer_remaining)/3600 developers_remaining,
    SUM(tester_remaining)/3600 testers_remaining,
    SUM(developer_remaining + tester_remaining)/3600 all_remaining
FROM issues_next_sprint;
```

# Отчет по трудозатратам тестирования в компонентах
```sql
WITH issue_stat AS (
    SELECT
        component_name AS name,
        COUNT(*) issues,
        SUM(tester_estimated) AS estimated,
        SUM(tester_logged) AS logged
    FROM it_issue
    WHERE tester_estimated > 0 AND status_name='Закрыто'
    GROUP BY component_name
)
SELECT
    CASE WHEN name='' THEN '-NOT-SPECIFIED-' ELSE name END AS name,
    issues, -- Number of issues
    CONCAT(round((estimated/issues)::numeric/60/60, 2), ' h') AS average_time_estimated, -- Среднее запланированное (h)
    CONCAT(round((logged/issues)::numeric/60/60, 2), ' h') AS average_time_logged, -- Среднее время на задачу (h)
    estimated,
    logged, -- Timespent
    estimated - logged AS diff -- Точность оценки
FROM issue_stat
ORDER BY logged DESC;
```

# Отчет по трудозатратам тестирования в репозиториях
```sql
WITH issue_stat AS (
    SELECT
        repository_name AS name,
        COUNT(*) issues,
        SUM(tester_estimated) AS estimated,
        SUM(tester_logged) AS logged
    FROM it_issue
    WHERE tester_estimated > 0 AND status_name='Закрыто'
    GROUP BY repository_name
)
SELECT
    CASE WHEN name='' THEN '-NOT-SPECIFIED-' ELSE name END AS name,
    issues, -- Number of issues
    CONCAT(round((estimated/issues)::numeric/60/60, 2), ' h') AS average_time_estimated, -- Среднее запланированное (h)
    CONCAT(round((logged/issues)::numeric/60/60, 2), ' h') AS average_time_logged, -- Среднее время на задачу (h)
    estimated,
    logged, -- Timespent
    estimated - logged AS diff -- Точность оценки
FROM issue_stat
ORDER BY logged DESC;
```

# Отчет по трудозатратам разработки в компонентах
```sql
WITH issue_stat AS (
    SELECT
        component_name AS name,
        COUNT(*) issues,
        SUM(developer_estimated) AS estimated,
        SUM(developer_logged) AS logged
    FROM it_issue
    WHERE developer_estimated > 0 AND status_name='Закрыто' AND updated_at BETWEEN '${__from:date:YYYY-MM-DD}T00:00:00' AND '${__to:date:YYYY-MM-DD}T23:59:59'
    GROUP BY component_name
)
SELECT
    CASE WHEN name='' THEN '-NOT-SPECIFIED-' ELSE name END AS name,
    issues, -- Number of issues
    CONCAT(round((estimated/issues)::numeric/60/60, 2), ' h') AS average_time_estimated, -- Среднее запланированное (h)
    CONCAT(round((logged/issues)::numeric/60/60, 2), ' h') AS average_time_logged, -- Среднее время на задачу (h)
    estimated,
    logged, -- Timespent
    estimated - logged AS diff -- Точность оценки
FROM issue_stat
ORDER BY logged DESC;
```
