## Changed Features/Behaviour in 5.0.0

### 1. BPS 4.9.9–4.9.x Support Dropped
We assume that you are using BPS 5.x.

### 2. Display Filter Names Changed (breaking for hooks)

| Old filter | New filter |
|---|---|
| `pp_loc_show_field_data` | `pp_loc_field_data` |
| `pp_loc_show_field_value` | `pp_loc_field_value` |
The new `pp_loc_field_value` also passes extra args (`$type`, `$id`) vs the old one-arg version.

### 3. Text domain Changed
- From `bp-profile-location` to `bp-xprofile-location`.

### 4. Admin Notice for Missing BuddyPress - Removed
The Old version showed an admin notice if neither BuddyPress nor BuddyBoss was active. Now, we don't need that by hooking to bp_loaded.
