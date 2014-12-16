<?php
/**
 * Doctrine ORM
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Query\AST\OrderByClause;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;

/**
 * Wraps the query in order to select root entity IDs for pagination.
 *
 * Given a DQL like `SELECT u FROM User u` it will generate an SQL query like:
 * SELECT DISTINCT <id> FROM (<original SQL>) LIMIT x OFFSET y
 *
 * Works with composite keys but cannot deal with queries that have multiple
 * root entities (e.g. `SELECT f, b from Foo, Bar`)
 *
 * @author Sander Marechal <s.marechal@jejik.com>
 */
class LimitSubqueryOutputWalker extends SqlWalker
{
    /**
     * @var \Doctrine\DBAL\Platforms\AbstractPlatform
     */
    private $platform;

    /**
     * @var \Doctrine\ORM\Query\ResultSetMapping
     */
    private $rsm;

    /**
     * @var array
     */
    private $queryComponents;

    /**
     * @var int
     */
    private $firstResult;

    /**
     * @var int
     */
    private $maxResults;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * The quote strategy.
     *
     * @var \Doctrine\ORM\Mapping\QuoteStrategy
     */
    private $quoteStrategy;

    /**
     * Constructor.
     *
     * Stores various parameters that are otherwise unavailable
     * because Doctrine\ORM\Query\SqlWalker keeps everything private without
     * accessors.
     *
     * @param \Doctrine\ORM\Query              $query
     * @param \Doctrine\ORM\Query\ParserResult $parserResult
     * @param array                            $queryComponents
     */
    public function __construct($query, $parserResult, array $queryComponents)
    {
        $this->platform = $query->getEntityManager()->getConnection()->getDatabasePlatform();
        $this->rsm = $parserResult->getResultSetMapping();
        $this->queryComponents = $queryComponents;

        // Reset limit and offset
        $this->firstResult = $query->getFirstResult();
        $this->maxResults = $query->getMaxResults();
        $query->setFirstResult(null)->setMaxResults(null);

        $this->em               = $query->getEntityManager();
        $this->quoteStrategy    = $this->em->getConfiguration()->getQuoteStrategy();

        parent::__construct($query, $parserResult, $queryComponents);
    }

    /**
     * Walks down a SelectStatement AST node, wrapping it in a SELECT DISTINCT.
     *
     * @param SelectStatement $AST
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    public function walkSelectStatement(SelectStatement $AST)
    {
        // Remove order by clause from the inner query
        // It will be re-appended in the outer select generated by this method
        $orderByClause = $AST->orderByClause;
        $AST->orderByClause = null;

        // Set every select expression as visible(hidden = false) to
        // make $AST have scalar mappings properly - this is relevant for referencing selected
        // fields from outside the subquery, for example in the ORDER BY segment
        $hiddens = array();

        foreach ($AST->selectClause->selectExpressions as $idx => $expr) {
            $hiddens[$idx] = $expr->hiddenAliasResultVariable;
            $expr->hiddenAliasResultVariable = false;
        }

        $innerSql = parent::walkSelectStatement($AST);

        // Restore hiddens
        foreach ($AST->selectClause->selectExpressions as $idx => $expr) {
            $expr->hiddenAliasResultVariable = $hiddens[$idx];
        }

        // Find out the SQL alias of the identifier column of the root entity.
        // It may be possible to make this work with multiple root entities but that
        // would probably require issuing multiple queries or doing a UNION SELECT.
        // So for now, it's not supported.

        // Get the root entity and alias from the AST fromClause.
        $from = $AST->fromClause->identificationVariableDeclarations;
        if (count($from) !== 1) {
            throw new \RuntimeException("Cannot count query which selects two FROM components, cannot make distinction");
        }

        $fromRoot       = reset($from);
        $rootAlias      = $fromRoot->rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass      = $this->queryComponents[$rootAlias]['metadata'];
        $rootIdentifier = $rootClass->identifier;

        // For every identifier, find out the SQL alias by combing through the ResultSetMapping
        $sqlIdentifier = array();
        foreach ($rootIdentifier as $property) {
            if (isset($rootClass->fieldMappings[$property])) {
                foreach (array_keys($this->rsm->fieldMappings, $property) as $alias) {
                    if ($this->rsm->columnOwnerMap[$alias] == $rootAlias) {
                        $sqlIdentifier[$property] = $alias;
                    }
                }
            }

            if (isset($rootClass->associationMappings[$property])) {
                $joinColumn = $rootClass->associationMappings[$property]['joinColumns'][0]['name'];

                foreach (array_keys($this->rsm->metaMappings, $joinColumn) as $alias) {
                    if ($this->rsm->columnOwnerMap[$alias] == $rootAlias) {
                        $sqlIdentifier[$property] = $alias;
                    }
                }
            }
        }

        if (count($sqlIdentifier) === 0) {
            throw new \RuntimeException('The Paginator does not support Queries which only yield ScalarResults.');
        }

        if (count($rootIdentifier) != count($sqlIdentifier)) {
            throw new \RuntimeException(sprintf(
                'Not all identifier properties can be found in the ResultSetMapping: %s',
                implode(', ', array_diff($rootIdentifier, array_keys($sqlIdentifier)))
            ));
        }

        // Build the counter query
        $sql = sprintf('SELECT DISTINCT %s FROM (%s) dctrn_result',
            implode(', ', $sqlIdentifier), $innerSql);

        // http://www.doctrine-project.org/jira/browse/DDC-1958
        $sql = $this->preserveSqlOrdering($sqlIdentifier, $innerSql, $sql, $orderByClause);

        // Apply the limit and offset.
        $sql = $this->platform->modifyLimitQuery(
            $sql, $this->maxResults, $this->firstResult
        );

        // Add the columns to the ResultSetMapping. It's not really nice but
        // it works. Preferably I'd clear the RSM or simply create a new one
        // but that is not possible from inside the output walker, so we dirty
        // up the one we have.
        foreach ($sqlIdentifier as $property => $alias) {
            $this->rsm->addScalarResult($alias, $property);
        }

        return $sql;
    }

    /**
     * Generates new SQL for SQL Server, Postgresql, or Oracle if necessary.
     *
     * @param array           $sqlIdentifier
     * @param string          $innerSql
     * @param string          $sql
     * @param OrderByClause   $orderByClause
     *
     * @return string
     */
    public function preserveSqlOrdering(array $sqlIdentifier, $innerSql, $sql, $orderByClause)
    {
        // Get order by clause as a string
        $orderBy = null;
        if ($orderByClause instanceof OrderByClause) {
            $orderBy = $this->walkOrderByClause($orderByClause);
        }

        // If the sql statement has an order by clause, we need to wrap it in a new select distinct
        // statement
        if ($orderBy) {
            // Rebuild the order by clause to work in the scope of the new select statement
            list($sqlOrderColumns, $orderBy) = $this->rebuildOrderByClauseForOuterScope($orderBy);

            // Identifiers are always included in the select list, so there's no need to include them twice
            $sqlOrderColumns = array_diff($sqlOrderColumns, $sqlIdentifier);

            // Build the select distinct statement
            $sql = sprintf(
                'SELECT DISTINCT %s FROM (%s) dctrn_result%s',
                implode(', ', array_merge($sqlIdentifier, $sqlOrderColumns)),
                $innerSql,
                $orderBy
            );
        }

        return $sql;
    }

    /**
     * Generates a new order by clause that works in the scope of a select query wrapping the original
     *
     * @param string $orderByClause
     * @return array
     */
    protected function rebuildOrderByClauseForOuterScope($orderByClause) {
        $dqlAliasToSqlTableAliasMap
            = $searchPatterns
            = $replacements
            = $dqlAliasToClassMap
            = $replacedAliases
            = array();

        // Generate DQL alias -> SQL table alias mapping
        foreach(array_keys($this->rsm->aliasMap) as $dqlAlias) {
            $dqlAliasToClassMap[$dqlAlias] = $class = $this->queryComponents[$dqlAlias]['metadata'];
            $dqlAliasToSqlTableAliasMap[$dqlAlias] = $this->getSQLTableAlias($class->getTableName(), $dqlAlias);
        }

        // Pattern to find table path expressions in the order by clause
        $fieldSearchPattern = "/(?<![a-z0-9_])%s\.%s(?![a-z0-9_])/i";

        // Generate search patterns for each field's path expression in the order by clause
        foreach($this->rsm->fieldMappings as $fieldAlias => $columnName) {
            $dqlAliasForFieldAlias = $this->rsm->columnOwnerMap[$fieldAlias];
            $columnName = $this->quoteStrategy->getColumnName(
                $columnName,
                $dqlAliasToClassMap[$dqlAliasForFieldAlias],
                $this->em->getConnection()->getDatabasePlatform()
            );

            $sqlTableAliasForFieldAlias = $dqlAliasToSqlTableAliasMap[$dqlAliasForFieldAlias];

            $searchPatterns[] = sprintf($fieldSearchPattern, $sqlTableAliasForFieldAlias, $columnName);
            $replacements[] = $fieldAlias;
        }

        // Scalar expression aliases will not be modified in the order by clause, but will
        // be included in the select list of the wrapping query
        $scalarSearchPattern = "/(?<![a-z0-9_])%s(?![a-z0-9_])/i";
        foreach(array_keys($this->rsm->scalarMappings) as $scalarField) {
            if(preg_match(sprintf($scalarSearchPattern, $scalarField), $orderByClause)) {
                $replacedAliases[] = $scalarField;
            }
        }

        // Replace path expressions in the order by clause with their column alias
        foreach($searchPatterns as $index => $pattern) {
            $newOrderByClause = preg_replace($pattern, $replacements[$index], $orderByClause);
            if ($newOrderByClause !== $orderByClause) {
                $orderByClause = $newOrderByClause;
                $replacedAliases[] = $replacements[$index];
            }
        }

        return array($replacedAliases, $orderByClause);
    }
}
