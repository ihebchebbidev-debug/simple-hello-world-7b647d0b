Enum app_role { technician; manager; admin }
Enum log_level { info; warn; error; debug }
Enum posting_status { pending; synced; failed; conflict }
Enum field_type { string; number; date; boolean; select }
Enum price_entity_type { water; fertilizer; pesticide; labor }
Enum operation_type { irrigation; fertilization; phytosanitary; harvest }

Table users {
  id UUID [pk]
  name VARCHAR(100) [not null]
  email VARCHAR(255) [not null, unique]
  password_hash VARCHAR(255) [not null]
  is_active BOOLEAN [not null, default: true]
  last_login_at TIMESTAMPTZ
  preferred_lang VARCHAR(5) [not null, default: 'fr']
  created_at TIMESTAMPTZ [not null]
  updated_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_by UUID [ref: > users.id]
}

Table user_roles {
  id UUID [pk]
  user_id UUID [not null, ref: > users.id]
  role app_role [not null]
  assigned_at TIMESTAMPTZ [not null]
  assigned_by UUID [ref: > users.id]
  indexes { (user_id, role) [unique] }
}

Table plots {
  id UUID [pk]
  name VARCHAR(100) [not null]
  surface_area_ha DECIMAL(10,4) [not null]
  crop_type VARCHAR(100)
  variety VARCHAR(100)
  season_start_date DATE
  is_active BOOLEAN [not null, default: true]
  created_at TIMESTAMPTZ [not null]
  updated_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_by UUID [ref: > users.id]
}

Table water_config [note: 'NO price here - use price_history'] {
  id UUID [pk]
  unit VARCHAR(20) [not null, note: 'e.g. m3, litres, mm']
  is_active BOOLEAN [not null, default: true]
  created_at TIMESTAMPTZ [not null]
  updated_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_by UUID [ref: > users.id]
}

Table fertilizers [note: 'NO price here - use price_history'] {
  id UUID [pk]
  name VARCHAR(100) [not null]
  unit VARCHAR(20) [not null]
  n_percent DECIMAL(5,2) [not null, default: 0]
  p_percent DECIMAL(5,2) [not null, default: 0]
  k_percent DECIMAL(5,2) [not null, default: 0]
  is_active BOOLEAN [not null, default: true]
  created_at TIMESTAMPTZ [not null]
  updated_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_by UUID [ref: > users.id]
}

Table pesticides [note: 'NO price here - use price_history'] {
  id UUID [pk]
  name VARCHAR(100) [not null]
  unit VARCHAR(20) [not null]
  chemical_composition TEXT
  is_active BOOLEAN [not null, default: true]
  created_at TIMESTAMPTZ [not null]
  updated_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_by UUID [ref: > users.id]
}

Table labor_config [note: 'NO daily_rate here - use price_history'] {
  id UUID [pk]
  is_active BOOLEAN [not null, default: true]
  created_at TIMESTAMPTZ [not null]
  updated_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_by UUID [ref: > users.id]
}

Table price_history [note: 'SINGLE SOURCE OF TRUTH for all prices'] {
  id UUID [pk]
  entity_type price_entity_type [not null, note: 'water|fertilizer|pesticide|labor']
  entity_id UUID [note: 'FK to the config table row']
  price_per_unit DECIMAL(12,4) [not null]
  unit VARCHAR(20)
  effective_from DATE [not null]
  effective_to DATE [note: 'NULL = currently active']
  created_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  indexes { (entity_type, entity_id, effective_from) }
}

Table entity_custom_fields {
  id UUID [pk]
  entity_type VARCHAR(50) [not null]
  field_name VARCHAR(100) [not null]
  field_label VARCHAR(100) [not null]
  field_type field_type [not null]
  is_required BOOLEAN [not null, default: false]
  select_options JSONB
  display_order INTEGER [not null, default: 0]
  is_active BOOLEAN [not null, default: true]
  created_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  indexes { (entity_type, field_name) [unique] }
}

Table entity_custom_values {
  id UUID [pk]
  custom_field_id UUID [not null, ref: > entity_custom_fields.id]
  entity_type VARCHAR(50) [not null]
  entity_id UUID [not null]
  value_text TEXT
  value_number DECIMAL(20,6)
  value_date DATE
  value_bool BOOLEAN
  created_at TIMESTAMPTZ [not null]
  updated_at TIMESTAMPTZ [not null]
  indexes {
    (custom_field_id, entity_id) [unique]
    (entity_type, entity_id)
  }
}

Table irrigation_operations {
  id UUID [pk]
  plot_id UUID [not null, ref: > plots.id]
  operation_date DATE [not null]
  water_quantity DECIMAL(10,2) [not null]
  unit_at_entry VARCHAR(20) [not null, note: 'snapshot']
  price_at_entry DECIMAL(12,4) [not null, note: 'snapshot from price_history']
  posting_id UUID [ref: > postings.id]
  created_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_at TIMESTAMPTZ [not null]
  updated_by UUID [ref: > users.id]
}

Table fertilization_operations {
  id UUID [pk]
  plot_id UUID [not null, ref: > plots.id]
  fertilizer_id UUID [not null, ref: > fertilizers.id]
  operation_date DATE [not null]
  quantity_applied DECIMAL(10,2) [not null]
  n_at_entry DECIMAL(5,2) [not null, note: 'NPK snapshot']
  p_at_entry DECIMAL(5,2) [not null]
  k_at_entry DECIMAL(5,2) [not null]
  price_at_entry DECIMAL(12,4) [not null, note: 'snapshot from price_history']
  posting_id UUID [ref: > postings.id]
  created_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_at TIMESTAMPTZ [not null]
  updated_by UUID [ref: > users.id]
}

Table phytosanitary_operations {
  id UUID [pk]
  plot_id UUID [not null, ref: > plots.id]
  pesticide_id UUID [not null, ref: > pesticides.id]
  operation_date DATE [not null]
  quantity_applied DECIMAL(10,2) [not null]
  target_pest VARCHAR(255)
  remarks TEXT
  price_at_entry DECIMAL(12,4) [not null, note: 'snapshot from price_history']
  posting_id UUID [ref: > postings.id]
  created_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_at TIMESTAMPTZ [not null]
  updated_by UUID [ref: > users.id]
}

Table harvest_operations {
  id UUID [pk]
  plot_id UUID [not null, ref: > plots.id]
  operation_date DATE [not null]
  num_workers INTEGER [not null]
  days_worked DECIMAL(5,2) [not null]
  quantity_harvested DECIMAL(10,2) [not null]
  daily_rate_at_entry DECIMAL(12,4) [not null, note: 'snapshot from price_history']
  posting_id UUID [ref: > postings.id]
  created_at TIMESTAMPTZ [not null]
  created_by UUID [ref: > users.id]
  updated_at TIMESTAMPTZ [not null]
  updated_by UUID [ref: > users.id]
}

Table postings {
  id UUID [pk]
  client_id VARCHAR(100) [not null, unique]
  operation_type operation_type [not null]
  payload JSONB [not null]
  status posting_status [not null, default: 'pending']
  error_message TEXT
  retry_count INTEGER [not null, default: 0]
  device_info JSONB
  submitted_at TIMESTAMPTZ [not null]
  synced_at TIMESTAMPTZ
  created_by UUID [ref: > users.id]
}

Table system_logs {
  id BIGSERIAL [pk]
  level log_level [not null, default: 'info']
  category VARCHAR(50) [not null]
  action VARCHAR(100) [not null]
  entity_type VARCHAR(50)
  entity_id UUID
  details JSONB
  ip_address INET
  user_agent TEXT
  user_id UUID [ref: > users.id]
  created_at TIMESTAMPTZ [not null]
  indexes {
    (created_at)
    (user_id, created_at)
    (entity_type, entity_id)
  }
}