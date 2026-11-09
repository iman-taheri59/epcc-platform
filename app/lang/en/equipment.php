<?php

return [
    'title' => 'Equipment',
    'scope' => 'Equipment is modelled far enough for a pipe to terminate at it. Internals, trays, seals, drivers, foundations and preservation are deliberately out of scope — none of them is needed for a line to land correctly.',

    'tag_number' => 'Tag',
    'equipment_type' => 'Type',
    'name_en' => 'Name (English)',
    'name_fa' => 'Name (Persian)',
    'service_description' => 'Service',
    'subsystem' => 'Subsystem',
    'state' => 'State',
    'design_pressure' => 'Design pressure',
    'design_temperature' => 'Design temperature',
    'design_code' => 'Design code',
    'external_ref' => 'External reference',

    'add' => 'Add equipment',
    'created' => 'Equipment added.',
    'empty' => 'No equipment has been modelled yet.',
    'nozzle_count' => 'nozzles',

    // The nozzle schedule — the classic deliverable.
    'schedule_title' => 'Nozzle schedule',
    'schedule_scope' => 'Every nozzle, what it is, and the line attached to it. A nozzle with nothing attached is UNCONNECTED unless it is a capped spare.',
    'tag' => 'Nozzle',
    'nozzle_type' => 'Type',
    'nps' => 'NPS',
    'dn' => 'DN',
    'end_type' => 'End',
    'rating' => 'Rating',
    'facing' => 'Facing',
    'connected_line' => 'Connected line',
    'unconnected' => 'UNCONNECTED',
    'spare' => 'Spare (capped)',
    'open_end' => 'Open end',
    'no_nozzles' => 'No nozzles have been declared yet.',

    'add_nozzle' => 'Add nozzle',
    'nozzle_added' => 'Nozzle added.',
    'is_spare' => 'This is a capped spare, not unfinished work',

    'subsystem_not_found' => 'That subsystem is not part of this project.',
];
