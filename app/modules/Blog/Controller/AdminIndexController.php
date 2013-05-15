<?php
/**
 * PhalconEye
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to phalconeye@gmail.com so we can send you a copy immediately.
 *
 */

namespace Blog\Controller;

/**
 * @RoutePrefix("/admin/module/blog", name="admin-blog")
 */
class AdminIndexController extends \Core\Controller\BaseAdmin
{
    /**
     * @Route("/", methods={"GET"}, name="admin-blog")
     */
    public function indexAction()
    {
    }

}
