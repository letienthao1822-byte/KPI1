USE kpi_system;

ALTER TABLE kpi_details
  ADD COLUMN manager_score DECIMAL(5,2) NULL AFTER self_score,
  ADD CONSTRAINT chk_detail_manager_score
    CHECK (manager_score IS NULL OR (manager_score >= 0 AND manager_score <= 100));

ALTER TABLE kpi_evaluations
  MODIFY COLUMN status ENUM('draft', 'submitted', 'manager_approved', 'approved', 'rejected')
    NOT NULL DEFAULT 'draft';
