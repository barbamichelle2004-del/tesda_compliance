<?php

class EligibilityService {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Initialize tasks for a trainee based on their course
     * Called when a new trainee is enrolled
     */
    public function initializeTraineeTasks($trainee_id, $course_id) {
        try {
            $tasks = $this->db->prepare("
                SELECT pt.id, pt.task_name
                FROM performance_tasks pt
                JOIN competency_standards cs ON pt.competency_id = cs.id
                JOIN qualifications q ON cs.qualification_id = q.id
                WHERE q.course_id = ?
                ORDER BY pt.id
            ");
            $tasks->execute([$course_id]);
            $task_list = $tasks->fetchAll(PDO::FETCH_ASSOC);
            
            $added = 0;
            foreach ($task_list as $task) {
                $check = $this->db->prepare("
                    SELECT COUNT(*) FROM evaluation_tracker 
                    WHERE trainee_id = ? AND task_id = ?
                ");
                $check->execute([$trainee_id, $task['id']]);
                
                if ($check->fetchColumn() == 0) {
                    $insert = $this->db->prepare("
                        INSERT INTO evaluation_tracker 
                        (trainee_id, task_id, remarks, updated_at) 
                        VALUES (?, ?, 'Not Done', NOW())
                    ");
                    $insert->execute([$trainee_id, $task['id']]);
                    $added++;
                }
            }
            
            return $added;
            
        } catch (Exception $e) {
            error_log("Error initializing tasks for trainee $trainee_id: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get progress for a single trainee
     */
    public function getTraineeProgress($trainee_id) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    t.id,
                    t.first_name,
                    t.last_name,
                    (SELECT COUNT(DISTINCT pt.id) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     JOIN qualifications q ON cs.qualification_id = q.id
                     JOIN trainees t2 ON t2.id = t.id
                     JOIN batches b ON t2.batch_id = b.id
                     WHERE q.course_id = b.course_id) as total_tasks,
                    (SELECT COUNT(DISTINCT et.task_id) 
                     FROM evaluation_tracker et
                     WHERE et.trainee_id = t.id AND et.remarks = 'Done') as completed_tasks
                FROM trainees t
                WHERE t.id = ?
            ");
            $stmt->execute([$trainee_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting trainee progress: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all trainees who completed all their tasks (eligible for pre-assessment)
     */
    public function getEligibleTrainees($course_id = null) {
        try {
            $sql = "
                SELECT 
                    t.id,
                    t.first_name,
                    t.last_name,
                    t.email,
                    t.contact,
                    c.course_name,
                    c.course_code,
                    (SELECT COUNT(*) 
                     FROM performance_tasks pt
                     JOIN competency_standards cs ON pt.competency_id = cs.id
                     JOIN qualifications q ON cs.qualification_id = q.id
                     WHERE q.course_id = c.id) as total_tasks,
                    (SELECT COUNT(*) 
                     FROM evaluation_tracker et
                     WHERE et.trainee_id = t.id AND et.remarks = 'Done') as completed_tasks,
                    CASE 
                        WHEN (SELECT COUNT(*) 
                              FROM performance_tasks pt
                              JOIN competency_standards cs ON pt.competency_id = cs.id
                              JOIN qualifications q ON cs.qualification_id = q.id
                              WHERE q.course_id = c.id) = 
                             (SELECT COUNT(*) 
                              FROM evaluation_tracker et
                              WHERE et.trainee_id = t.id AND et.remarks = 'Done')
                        AND (SELECT COUNT(*) 
                              FROM performance_tasks pt
                              JOIN competency_standards cs ON pt.competency_id = cs.id
                              JOIN qualifications q ON cs.qualification_id = q.id
                              WHERE q.course_id = c.id) > 0 THEN 1
                        ELSE 0
                    END as is_eligible
                FROM trainees t
                JOIN batches b ON t.batch_id = b.id
                JOIN courses c ON b.course_id = c.id
            ";
            
            if ($course_id) {
                $sql .= " WHERE c.id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$course_id]);
            } else {
                $stmt = $this->db->query($sql);
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting eligible trainees: " . $e->getMessage());
            return [];
        }
    }
}
?>