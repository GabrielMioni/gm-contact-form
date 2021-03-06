<?php

require_once(ABSPATH . WPINC . '/class-phpmailer.php');

/**
 * @package     GM-Contact-Form
 * @author      Gabriel Mioni <gabriel@gabrielmioni.com>
 */


/**
 * Validates form inputs from the HTML form built at gm-contact-html.php. Most interaction with this class will
 * be via an Ajax call made by the gm_contact_ajax() function found in gm-contact-form.php, however if JS is disabled
 * the class is also called at the non-ajax handler gm_contact_check_api() in gm-contact-form.php
 *
 * If request is via Ajax, the class can return a response. Returns 1 if submit is successful. Else returns a JSON
 * encoded array that can be parsed to display error messages. If JS *is* disabled, previously submitted input values
 * and validation error messages are stored in $_SESSION variables that are used to display content and then destroyed
 * at gm-form-html.php
 */
class gm_contact_email {

    /** @var bool|null  flag for whether the honeypot input is empty. */
    protected $honey_pot_is_empty = null;

    /** @var bool|null  flag for whether request is Ajax.  */
    protected $is_ajax      = null;

    /** @var array  Holds input data from the HTML contact form. */
    protected $input_data   = array();
    /** @var array  Holds array elements representing which inputs failed and why. 0 = empty, -1 = invalid. */
    protected $errors       = array();
    /** @var array  Holds text error messages that will be displayed on the email contact form. */
    protected $error_msgs   = array();


    public function __construct()
    {
        $this->honey_pot_is_empty    = isset($_POST['covfefe']) ? false : true;
        $this->is_ajax               = isset($_POST['is_ajax']) ? true : false;

        $this->input_data['name']    = $this->check_text('name', $this->errors);
        $this->input_data['email']   = $this->check_email('email', $this->errors);
        $this->input_data['company'] = $this->check_text('company');
        $this->input_data['message'] = $this->check_text('message', $this->errors);

        $this->error_msgs = $this->build_error_msgs($this->errors);

        $this->try_send_email($this->error_msgs, $this->input_data);

        $this->non_ajax_processing($this->is_ajax, $this->error_msgs, $this->input_data);
    }

    /**
     * Checks $_POST values. If the input is required, the error array can be set as an argument. If the $error_array
     * is an array and the input value is blank, $error_array[$post_index] is set to 0.
     *
     * @param   $post_index     string      The index name for the $_POST value being checked.
     * @param   $error_array    null|array  Default is null. If an array is provided, input value is required.
     * @return  string          string      Either whitespace or sanitized value of $_POST[$post_index]
     */
    protected function check_text($post_index, &$error_array = null)
    {
        $input = isset($_POST[$post_index]) ? trim($_POST[$post_index]) : '';

        if ($input === '' && is_array($error_array))
        {
            $error_array[$post_index] = 0;
        }

        return strip_tags($input);
    }

    /**
     * Checks if email is either blank or invalid. If email is blank, $error_array[$email_index] is 0. If email is
     * invalid, $error_array[$email_index] is -1.
     *
     * @param   $email_index        string  The index name for the email input.
     * @param   array $error_array          The error array that will be passed error data by reference.
     * @return  string              string  Either whitespace or sanitized value of $_POST[$email_index]
     */
    protected function check_email($email_index, array &$error_array)
    {
        $email_input = $this->check_text($email_index, $error_array);

        if ($email_input === '')
        {
            return '';
        }

        /* Clean the email input */
        $email = filter_var($email_input, FILTER_SANITIZE_EMAIL);

        /* Validate email */
        $validate = filter_var($email, FILTER_VALIDATE_EMAIL);

        if ($validate === false)
        {
            $error_array[$email_index] = -1;
        }

        return $email;
    }

    /**
     * Builds an array of validation error messages that can be displayed to the user submitting the email.
     *
     * @param   array   $error_array    The array containing results from $_POST input checks.
     * @return  array                   An array with validation messages for the person submitting the email.
     */
    protected function build_error_msgs(array $error_array)
    {
        $error_msgs = array();

        foreach ($error_array as $key=>$value)
        {
            switch ($value)
            {
                case 0:
                    // The input was blank.
                    $msg = ucfirst("$key cannot be blank");
                    break;
                case -1:
                    // The input was invalid.
                    $msg = "Please make sure the $key field is in valid format";
                    break;
                default:
                    // Something is amiss
                    $msg = "The $key input is incorrect.";
                    break;
            }
            $error_msgs[$key] = $msg;
        }

        return $error_msgs;
    }

    /**
     * If no errors are present, send the email.
     *
     * @param   array   $error_msgs     Array of error messages.
     * @param   array   $input_data     Values from the email submit form.
     * @return  bool    True if email was sent. Else false.
     */
    protected function try_send_email(array &$error_msgs, array $input_data)
    {
        // If there are errors, just return false.
        if (!empty($error_msgs))
        {
            return false;
        }

        $sender_name    = stripslashes($input_data['name']);
        $sender_email   = $input_data['email'];
        $Sender_company = stripslashes($input_data['company']);
        $sender_message = stripslashes($input_data['message']);

        $content  = "Name: $sender_name \n";
        $content .= "Email: $sender_email \n";
        $content .= "Company: $Sender_company \n\n";
        $content .= "Message: \n\n";
        $content .= $sender_message;

        $mail = new PHPMailer;

        $gm_options        = $this->get_receiver_data();

        $recipient_address = $gm_options['address'];
        $recipient_name    = $gm_options['name'];
        $recipient_site    = $this->get_sitename();

        $mail->setFrom($sender_email, $sender_name);
        $mail->addAddress($recipient_address, $recipient_name);
        $mail->Subject  = 'Contact From ' . $recipient_site;
        $mail->Body     = $content;

        if(!$mail->Send())
        {
            error_log("PHPMailer: " . $mail->ErrorInfo);
            $error_msgs['generic'] = 'There was a problem sending your email. Please try again later.';

            return false;
        }
        else {
            return true;
        }
    }

    /**
     * Looks for options data from the gm_contact_address table. If no data is found, try getting an email address
     * from the admin_email table.
     *
     * @return array    Array will contain 'address' and 'name' elements.
     */
    protected function get_receiver_data()
    {
        $gm_options = get_option('gm_contact_address');

        $receiver_data = array();

        $receiver_data['address'] = isset($gm_options['address']) ? $gm_options['address'] : '';
        $receiver_data['name'] = isset($gm_options['name']) ? $gm_options['name'] : 'Admin';

        if ($receiver_data['address'] !== '')
        {
            $validate_gm_email = filter_var($receiver_data['address'], FILTER_SANITIZE_EMAIL);

            if ($validate_gm_email !== false)
            {
                return $receiver_data;
            }
        }

        $admin_email = get_option('admin_email');

        $receiver_data['address'] = is_string($admin_email) ? $admin_email : '';

        return $receiver_data;

    }

    /**
     * Check WordPress option table site_url for data. If a URL is present, get the site name. Else return
     * the generic 'Contact Form.'
     *
     * @return string   If a URL is present on the site_url table, return site name. Else return 'Contact Form.'
     */
    protected function get_sitename()
    {
        $generic = 'Contact Form';
        $wp_url = get_option('site_url');

        if (is_string($wp_url) && trim($wp_url) !== '')
        {
            $url_parse = parse_url($wp_url);
            $site_name = isset($url_parse['host']) ? $url_parse['host'] : $generic;

            return $site_name;
        } else {
            return $generic;
        }
    }

    /**
     * If this isn't an Ajax call, then do the following:
     * - 1. Unset previous $_SESSION messages.
     * - 2. If no errors (all inputs are valid and the email has been sent), set $_SESSION success message
     * - 3. If there were errors, set $_SESSION messages.
     * - 4. Redirect to referer.
     *
     * @param $is_ajax  bool    Flag stating whether or not the request is Ajax. Set by $_POST['is_ajax']
     * @param array     $error_msgs     Error messages array. Used to build error session variables.
     * @param array     $input_data     Input data.
     */
    protected function non_ajax_processing($is_ajax, array $error_msgs, array $input_data)
    {
        if ($is_ajax === false)
        {
            if (empty($error_msgs))
            {
                $_SESSION['gm_success'] = 1;
            } else {
                $this->set_session_message($error_msgs, 'gm_error_');
                $this->set_session_message($input_data, 'gm_value_');
            }

            $this->do_redirect();
        }
    }

    /**
     * Loop through $message_array and set $_SESSION error messages with the value of each element in $message_array
     *
     * @param   array   $message_array  Array of error messages.
     * @param   string  $prepend_key    The value that should be used to build the beginning of the new $_SESSION index.
     */
    protected function set_session_message(array $message_array, $prepend_key)
    {
        foreach ($message_array as $key=> $value)
        {
            $session_index = $prepend_key . $key;
            $_SESSION[$session_index] = $value;
        }
    }

    /**
     * Sends user back to the page from which the submit page was submitted.
     */
    protected function do_redirect()
    {
        header('Location: ' . strtok($_SERVER["HTTP_REFERER"],'?'));
        exit();
    }

    /**
     * Returns a response for Ajax requests.
     *
     * @return  int|string  returns 1 if no errors are present. Else returns JSON encoded string with error message data.
     */
    public function return_ajax_msg()
    {
        $error_msgs = $this->error_msgs;

        if (empty($error_msgs))
        {
            return 1;
        }

        return json_encode($error_msgs, true);
    }

}