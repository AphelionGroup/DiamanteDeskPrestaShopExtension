<?php

/**
 * Copyright (c) 2014 Eltrino LLC (http://eltrino.com)
 *
 * Licensed under the Open Software License (OSL 3.0).
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://opensource.org/licenses/osl-3.0.php
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@eltrino.com so we can send you a copy immediately.
 */
class DiamanteDeskMyTicketsModuleFrontController extends ModuleFrontController
{
    const TICKETS_PER_PAGE = 25;
    const TOTAL_RESULT_HEADER = 'X-Total';

    public $ssl = true;
    public $auth = true;
    public $page_name = 'My Tickets';

    private $diamanteUsers = array();
    private $oroUsers = array();

    public function __construct()
    {
        parent::__construct();
        $this->context = Context::getContext();
        $api = getDiamanteDeskApi();
        $this->diamanteUsers = $api->getDiamanteUsers();
        $this->oroUsers = $api->getUsers();
    }

    public function initContent()
    {

        if (isset($_GET['ticket'])) {
            $this->initTicketContent();
            return;
        }

        if (Tools::isSubmit('submitTicket')) {
            Tools::safePostVars();

            if (!$_POST['subject'] || !$_POST['description']) {
                $this->errors[] = 'All fields are required. Please fill all fields and try again.';
            } else {
                $data = $_POST;
                $api = getDiamanteDeskApi();
                $diamanteUser = $api->getOrCreateDiamanteUser($this->context->customer);
                $data['reporter'] = DiamanteDesk_Api::TYPE_DIAMANTE_USER . $diamanteUser->id;
                if (!getDiamanteDeskApi()->createTicket($data)) {
                    $this->errors[] = 'Something went wrong. Please try again later or contact us';
                } else {
                    $this->context->smarty->assign('success', 'Ticket was successfully created.');
                }
            }
        }

        $this->display_column_left = false;
        $this->display_column_right = false;

        parent::initContent();

        $api = getDiamanteDeskApi();

        // get pagination info
        $currentPage = isset($_GET['p']) ? (int)$_GET['p'] : 1;
        $ticketsPerPage = static::TICKETS_PER_PAGE;
        $api->addFilter('page', $currentPage);
        $api->addFilter('limit', $ticketsPerPage);

        $customer = $this->context->customer;
        $diamanteUser = $api->getOrCreateDiamanteUser($customer);

        if (!$diamanteUser) {
            $this->errors[] = 'Something went wrong. Please try again later or contact us';
            $this->context->smarty->assign(array(
                'start' => 1,
                'stop' => 1,
                'p' => $currentPage,
                'range' => static::TICKETS_PER_PAGE,
                'pages_nb' => 1,
                'tickets' => array(),
                'diamantedesk_url' => Configuration::get('DIAMANTEDESK_SERVER_ADDRESS')
            ));
            $this->setTemplate('mytickets.tpl');
            return;
        }

        $api->addFilter('reporter', DiamanteDesk_Api::TYPE_DIAMANTE_USER . $diamanteUser->id);

        $tickets = $api->getTickets();

        $lastPage = ceil($api->resultHeaders[static::TOTAL_RESULT_HEADER] / static::TICKETS_PER_PAGE);

        /** format date */
        foreach ($tickets as $ticket) {
            $ticket->created_at = date("U", strtotime($ticket->created_at));
        }

        $this->context->smarty->assign(array(
            'start' => 1,
            'stop' => $lastPage,
            'p' => $currentPage,
            'range' => static::TICKETS_PER_PAGE,
            'pages_nb' => $lastPage,
            'tickets' => $tickets,
            'diamantedesk_url' => Configuration::get('DIAMANTEDESK_SERVER_ADDRESS')
        ));

        $this->setTemplate('mytickets.tpl');
    }

    public function initTicketContent()
    {

        if (Tools::isSubmit('submitComment')) {
            Tools::safePostVars();
            if (!$_POST['comment']) {
                $this->errors[] = 'All fields are required. Please fill all fields and try again.';
            } else {
                $api = getDiamanteDeskApi();
                $data = $_POST;
                $data['content'] = $data['comment'];
                $customer = $this->context->customer;
                $diamanteUser = $api->getOrCreateDiamanteUser($customer);
                $data['author'] = DiamanteDesk_Api::TYPE_DIAMANTE_USER . $diamanteUser->id;
                if (!getDiamanteDeskApi()->addComment($data)) {
                    $this->errors[] = 'Something went wrong. Please try again later or contact us';
                } else {
                    $this->context->smarty->assign('success', 'Comment successfully added');
                }
            }
        }

        $this->display_column_left = false;
        $this->display_column_right = false;

        parent::initContent();

        $api = getDiamanteDeskApi();

        $ticket = $api->getTicket((int)$_GET['ticket']);

        if ($ticket && $ticket->comments) {
            foreach ($ticket->comments as $comment) {
                $comment->authorData = $this->getAuthor($comment);
                $comment->created_at = date("U", strtotime($comment->created_at));
            }
        }

        $this->context->smarty->assign(array(
            'ticket' => $ticket
        ));

        $this->setTemplate('ticket.tpl');
    }

    /**
     * @param $comment
     * @return mixed
     */
    public function getAuthor($comment)
    {
        if ($comment->author_type . '_' == DiamanteDesk_Api::TYPE_DIAMANTE_USER) {
            foreach ($this->diamanteUsers as $user) {
                if ($comment->author == $user->id) {
                    $userData = new \stdClass();
                    $userData->firstName = $user->first_name;
                    $userData->lastName = $user->last_name;
                    return $userData;
                }
            }
        } else {
            foreach ($this->oroUsers as $user) {
                if ($comment->author == $user->id) {
                    return $user;
                }
            }
        }
    }
}