<?php

/*
 * get list of all organizations
 */
$app->get('/organizations', function() use ($app) {
    disable_cache($app);
	if_is_admin(function() use ($app) {
		try {
			$organizations = OrganizationQuery::create()->filterByDeleted(false)->find();
			$res           = array();
			foreach ($organizations as $organization) {
				$res[] = $organization->toArray();
			}
			ok($res);
		} catch (Exception $e) {
			error('io-error', $e->getMessage());
		}
	});
});

/*
 * creates new organization
 */
$app->post('/organizations', function() use ($app) {
    disable_cache($app);
    // only admins can create orgs
    if_is_admin(function() use ($app) {
        try {
            $params = json_decode($app->request()->getBody(), true);
            // check for weird characters
            if(!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $params['id'])) {
                error('bad-chars', 'Organization id must match Regex: /^[a-z0-9]+(-[a-z0-9]+)*$/');
                return;
            }
            // check if organization id already exists
            if (OrganizationQuery::create()->findPk($params['id'])) {
                error('id-already-exists', 'Sorry, there is already an organization with that id.');
                return;
            }
            $org = new Organization();
            $org->setId(strtolower($params['id']));
            $org->setName($params['name']);
            if (isset($params['default_theme'])) {
                $org->setDefaultTheme($params['default_theme']);
            }
            if (isset($params['type'])) {
                $org->updateSettings('type', $params['type']);
            }
            $org->setCreatedAt(time());
            $org->save();
            ok();
        } catch (Exception $e) {
            error('io-error', $e->getMessage());
        }
    });
});


/*
 * change organization
 */
$app->put('/organizations/:id', function($org_id) use ($app) {
    if_is_admin(function() use ($app, $org_id) {
        $org = OrganizationQuery::create()->findPk($org_id);
        if ($org) {
            $params = json_decode($app->request()->getBody(), true);
            if (isset($params['name'])) {
                $org->setName($params['name']);
            }
            if (isset($params['default_theme'])) {
                $org->setDefaultTheme($params['default_theme']);
            }
            $org->save();
            ok();
        } else {
            return error('unknown-organization', 'Organization not found');
        }
    });
});



/*
 * delete organization
 */
$app->delete('/organizations/:id', function($org_id) use ($app) {
    if_is_admin(function() use ($app, $org_id) {
        $org = OrganizationQuery::create()->findPk($org_id);
        if ($org) {
            $org->setDeleted(true);
        } else {
            return error('unknown-organization', 'Organization not found');
        }
    });
});


/*
 * add user to organization
 */
$app->post('/organizations/:id/users', function($org_id) use ($app) {
    if_is_admin(function() use ($app, $org_id) {
        $org = OrganizationQuery::create()->findPk($org_id);
        if ($org) {
            $data = json_decode($app->request()->getBody(), true);
            $alreadyInOrg = array();
            $added = [];
            foreach ($data as $u_id) {
                $u = UserQuery::create()->findPk($u_id);
                if ($u) {
                    // number of organization users
                    $c = UserOrganizationQuery::create()
                        ->filterByOrganization($org)
                        ->filterByInviteToken('', Criteria::NOT_EQUAL)
                        ->count();
                    if ($c > 0 && $org->hasUser($u)) {
                        $alreadyInOrg[] = $u->guessName();
                    } else {
                        $org->addUser($u);
                        $added[] = $u_id;
                        // make first user the admin
                        // if ($c == 0) {
                        //     $org->save();
                        //     $org->setRole($u, 'admin');
                        // }
                        DatawrapperHooks::execute(DatawrapperHooks::USER_ORGANIZATION_ADD, $org, $u);
                    }
                }
            }
            Action::logAction(DatawrapperSession::getUser(), 'add-team-member',
                '['.implode(',', $added).'] --> '.$org->getId());
            $org->save();
            ok(array('added' => count($added)));
        } else {
            return error('unknown-organization', 'Organization not found');
        }
    });
});

/*
 * remove user from organization
 */
$app->delete('/organizations/:id/users/:uid', function($org_id, $user_id) use ($app) {
    if_is_admin(function() use ($app, $org_id, $user_id) {
        $org = OrganizationQuery::create()->findPk($org_id);
        $user = UserQuery::create()->findPk($user_id);
        if ($org && $user) {
            $org->removeUser($user);
            $org->save();
            DatawrapperHooks::execute(DatawrapperHooks::USER_ORGANIZATION_REMOVE, $org, $user);
            Action::logAction(DatawrapperSession::getUser(), 'remove-team-member',
                $user_id.' from '.$org->getId());
            ok();
        } else {
            return error('unknown-organization-or-user', 'Organization or user not found');
        }
    });
});

/*
 * list all organizations in which the current user is a member
 */
$app->get('/organizations/user', function() use ($app) {
    $user = DatawrapperSession::getUser();

    if (!$user->isLoggedIn()) {
        error('access-denied', 'User is not logged in.');
        return;
    }

    $user_id = $user->getId();
    $organizations = UserOrganizationQuery::create()->findByUserId($user_id);
    $res = array();
    foreach ($organizations as $org) {
        if (!$org->getOrganization()->getDisabled())
            $res[] = $org->getOrganizationId();
    }

    ok($res);
});


/*
 * toggle plugin permissions of organization
 */
$app->put('/organizations/:id/plugins/:op/:plugin_id', function($org_id, $op, $plugin_id) use ($app) {
    if_is_admin(function() use ($app, $org_id, $op, $plugin_id) {
        $org = OrganizationQuery::create()->findPk($org_id);
        $plugin = PluginQuery::create()->findPk($plugin_id);
        if (!$org) return error('unknown-organization', 'Organization not found');
        if (!$plugin) return error('unknown-plugin', 'Plugin not found');
        if ($op == 'config') {
            $data = json_decode($app->request()->getBody(), true);
            // store custom config value
            $key = 'custom_config/' . $org->getId() . '/' . $data['key'];
            $q = PluginDataQuery::create()
                  ->filterByPlugin($plugin)
                  ->filterByKey($key)
                  ->findOne();
            if (is_null($data['value'])) {
                // remove value
                if ($q) $q->delete();
                ok();
            } else {
                // udpate value
                if (!$q) {
                    $q = new PluginData();
                    $q->setPlugin($plugin);
                    $q->setKey($key);
                }
                $q->setData($data['value']);
                $q->setStoredAt(time());
                $q->save();
                ok($q->toArray());
            }
        } else {
            // change plugin permission
            if ($org->hasPlugin($plugin)) {
                if ($op == 'remove' || $op == 'toggle') $org->removePlugin($plugin);
            } else {
                if ($op == '' || $op == 'toggle') $org->addPlugin($plugin);
            }
            $org->save();
            ok(array('active' => $org->hasPlugin($plugin)));
        }
    });
})->conditions(array('op' => '(remove|add|toggle|config)'));


/*
 * get charts of an organization
 */
$app->get('/organizations/:id/charts', function($org_id) use ($app) {
    disable_cache($app);
    $user = DatawrapperSession::getUser();
    $org = OrganizationQuery::create()->findPk($org_id);
    if ($org) {
        if ($org->hasUser($user) || $user->isAdmin()) {
            $query = ChartQuery::create()
                ->filterByDeleted(false)
                ->orderByLastModifiedAt(Criteria::DESC)
                ->filterByOrganization($org);
            // filter by visualization
            $vis = $app->request()->get('vis');
            if (isset($vis)) {
                $vis = explode(',', $vis);
                $conds = array();
                foreach ($vis as $v) {
                    $query->condition($conds[] = 'c'.count($conds), 'Chart.Type = ?', $v);
                }
                $query->where($conds, 'or');
            }
            // filter by month
            $months = $app->request()->get('months');
            if (isset($months)) {
                $months = explode(',', $months);
                $conds = array();
                foreach ($months as $m) {
                    $query->condition($conds[] = 'c'.count($conds), 'DATE_FORMAT(Chart.CreatedAt, "%Y-%m") = DATE_FORMAT(?, "%Y-%m")', $m.'-01');
                }
                $query->where($conds, 'or');
            }
            // filter by status
            $status = $app->request()->get('status');
            if (isset($status)) {
                $status = explode(',', $status);
                $conds = array();
                foreach ($status as $s) {
                    $query->condition($conds[] = 'c'.count($conds), 'Chart.LastEditStep ' .
                        ($s == 'published' ? ' >= 4' : 
                         $s == 'visualized' ? ' = 3' : '< 3'));
                }
                $query->where($conds, 'or');
            }
            // generally ignore charts that have no data
            $query->where('Chart.LastEditStep > 0');
            // filter by search query
            $q = $app->request()->get('search');
            if (!empty($q)) {
                $query->join('Chart.User')
                    ->condition('c1', 'Chart.Title LIKE ?', '%'.$q.'%')
                    ->condition('c2', 'Chart.Metadata LIKE ?', '%"intro":"%'.$q.'%"%')
                    ->condition('c3', 'Chart.Metadata LIKE ?', '%"source-name":"%'.$q.'%"%')
                    ->condition('c4', 'Chart.Metadata LIKE ?', '%"source-url":"%'.$q.'%"%')
                    ->condition('c5', 'Chart.Id LIKE ?', '%'.$q.'%')
                    ->condition('c6', 'User.Email LIKE ?', '%'.$q.'%')
                    ->condition('c7', 'User.Name LIKE ?', '%'.$q.'%')
                    ->where(array('c1','c2','c3','c4','c5','c6','c7'), 'or');
            }

            $total = $query->count();
            // pagination
            $pagesize = 48;
            $page = $app->request()->get('page');
            if (!isset($page)) $page = 0;
            $query->limit($pagesize)->offset($page * $pagesize);
            // query result
            $charts = $query->find();
            // return as json
            $res = array();
            foreach ($charts as $chart) {
                $res[] = $app->request()->get('expand') ? $chart->serialize() : $chart->shortArray();
            }
            ok(array(
                'total' => $total,
                'charts' => $res,
                'page' => $page,
                'numPages' => ceil($total / $pagesize)));
        } else {
            return error('access-denied', 'You are not allowed to do this..');
        }
    } else {
        return error('unknown-organization', 'Organization not found');
    }
});
