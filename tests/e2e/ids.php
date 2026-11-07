<?php
define('WP_USE_THEMES', false);
require $argv[1] . '/wp-load.php';
$cmd = $argv[2] ?? 'ids';
$ids = get_option('cfs_qa_ids');
if ($cmd === 'ids') { echo json_encode($ids); }
elseif ($cmd === 'group') { echo json_encode(get_post_meta((int)$argv[3], '_cfs_fields', true)); }
elseif ($cmd === 'meta') { $m=get_post_meta((int)$argv[3]); $o=array(); foreach($m as $k=>$v){ if($k[0]!=='_' || strpos($k,'_qa_')===0) $o[$k]=maybe_unserialize($v[0]); } ksort($o); echo json_encode($o); }
elseif ($cmd === 'opts') { global $wpdb; $o=array(); foreach($wpdb->get_results("select option_name n, option_value v from {$wpdb->options} where option_name like 'options\\_qa\\_opt\\_%'") as $r) $o[$r->n]=maybe_unserialize($r->v); ksort($o); echo json_encode($o); }
elseif ($cmd === 'umeta') { echo json_encode(get_user_meta((int)$argv[3], $argv[4], true)); }
elseif ($cmd === 'tmeta') { echo json_encode(get_term_meta((int)$argv[3], $argv[4], true)); }
elseif ($cmd === 'gmeta') { echo json_encode(get_post_meta((int)$argv[3], $argv[4], true)); }
elseif ($cmd === 'pmeta') { echo json_encode(get_post_meta((int)$argv[3], $argv[4], true)); }
elseif ($cmd === 'opt') { echo json_encode(get_option($argv[3], null)); }
elseif ($cmd === 'findgroup') { $q = get_posts(array('post_type'=>'cfs_field_group','post_status'=>'any','numberposts'=>1,'title'=>$argv[3])); echo $q ? $q[0]->ID : 0; }
elseif ($cmd === 'groupcount') { echo count(get_posts(array('post_type'=>'cfs_field_group','post_status'=>'any','numberposts'=>-1,'fields'=>'ids'))); }

