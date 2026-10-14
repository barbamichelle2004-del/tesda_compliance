<?php
// helpers/AuthHelper.php - Role-Based Access Control Helper
// NOTE: Main RBAC functions are in config/config.php
// This file contains additional helper functions only

/**
 * Get SQL WHERE clause for filtering by trainer's qualifications
 * Returns array with SQL fragment and parameters
 * 
 * @param string $table_alias The table alias to use in the filter (e.g., 't' for trainees, 'pt' for tasks)
 * @param string $id_column The column name to filter on (e.g., 'qualification_id')
 * @return array ['sql' => string, 'params' => array]
 */
if (!function_exists('getQualificationFilterClause')) {
    function getQualificationFilterClause($table_alias = 't', $id_column = 'qualification_id') {
        $context = getUserAccessContext();
        
        // Coordinator/Admin: no filter (full access)
        if ($context['is_coordinator']) {
            return ['sql' => '', 'params' => []];
        }
        
        // Trainer: filter by qualifications
        if ($context['is_trainer'] && !empty($context['qualification_ids'])) {
            $placeholders = implode(',', array_fill(0, count($context['qualification_ids']), '?'));
            return [
                'sql' => " AND {$table_alias}.{$id_column} IN ({$placeholders})",
                'params' => $context['qualification_ids']
            ];
        }
        
        // Trainer with no qualifications: return no results
        return ['sql' => ' AND 1=0', 'params' => []];
    }
}

/**
 * Build filtered WHERE clause for any module
 * 
 * @param string $table_alias The table alias for the main entity
 * @param string $id_column The column name to filter on
 * @param string $base_where Optional base WHERE clause
 * @return string
 */
if (!function_exists('buildFilteredWhere')) {
    function buildFilteredWhere($table_alias, $id_column = 'qualification_id', $base_where = '') {
        $context = getUserAccessContext();
        $filter = getQualificationFilterClause($table_alias, $id_column);
        
        if ($context['is_coordinator']) {
            return $base_where;
        }
        
        if (empty($base_where)) {
            return $filter['sql'];
        }
        
        return $base_where . ' ' . $filter['sql'];
    }
}

/**
 * Check if current user can access a specific qualification
 * 
 * @param int $qualification_id
 * @return bool
 */
if (!function_exists('canAccessQualification')) {
    function canAccessQualification($qualification_id) {
        $context = getUserAccessContext();
        
        if ($context['is_coordinator']) {
            return true;
        }
        
        if ($context['is_trainer']) {
            return in_array($qualification_id, $context['qualification_ids']);
        }
        
        return false;
    }
}

/**
 * Get user's display name with role badge
 * 
 * @return array
 */
if (!function_exists('getUserDisplayInfo')) {
    function getUserDisplayInfo() {
        $context = getUserAccessContext();
        return [
            'name' => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User',
            'role' => $context['role'],
            'is_coordinator' => $context['is_coordinator'],
            'qualifications' => $context['qualification_ids'],
            'department' => $context['department']
        ];
    }
}

/**
 * Get SQL for filtering trainees by trainer's qualifications
 * 
 * @param string $table_alias
 * @return array ['sql' => string, 'params' => array]
 */
if (!function_exists('getTraineeFilterClause')) {
    function getTraineeFilterClause($table_alias = 't') {
        $context = getUserAccessContext();
        
        if ($context['is_coordinator']) {
            return ['sql' => '', 'params' => []];
        }
        
        if ($context['is_trainer'] && !empty($context['qualification_ids'])) {
            $placeholders = implode(',', array_fill(0, count($context['qualification_ids']), '?'));
            
            // Filter by qualification_id (direct)
            $sql = " AND {$table_alias}.qualification_id IN ({$placeholders})";
            
            return ['sql' => $sql, 'params' => $context['qualification_ids']];
        }
        
        // Trainer with no qualifications: return no results
        return ['sql' => ' AND 1=0', 'params' => []];
    }
}

/**
 * Get SQL for filtering tasks by trainer's qualifications
 * 
 * @param string $table_alias
 * @return array
 */
if (!function_exists('getTaskFilterClause')) {
    function getTaskFilterClause($table_alias = 'pt') {
        $context = getUserAccessContext();
        
        if ($context['is_coordinator']) {
            return ['sql' => '', 'params' => []];
        }
        
        if ($context['is_trainer'] && !empty($context['qualification_ids'])) {
            $placeholders = implode(',', array_fill(0, count($context['qualification_ids']), '?'));
            return [
                'sql' => " AND EXISTS (
                    SELECT 1 FROM competency_standards cs
                    WHERE cs.id = {$table_alias}.competency_id
                    AND cs.qualification_id IN ({$placeholders})
                )",
                'params' => $context['qualification_ids']
            ];
        }
        
        return ['sql' => ' AND 1=0', 'params' => []];
    }
}

/**
 * Get SQL for filtering reports by trainer's qualifications
 * 
 * @param string $table_alias
 * @return array
 */
if (!function_exists('getReportFilterClause')) {
    function getReportFilterClause($table_alias = 't') {
        return getTraineeFilterClause($table_alias);
    }
}