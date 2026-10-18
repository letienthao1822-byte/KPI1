-- Apply once to databases created before target_type was added to KPI criteria.
ALTER TABLE kpi_criteria
  ADD COLUMN target_type VARCHAR(30) NOT NULL DEFAULT 'official' AFTER description;
