<?php

namespace App\Controller;

use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\FrozenTime;
use Cake\I18n\I18n;
use Cake\Log\Log;
use Cake\Mailer\Mailer;


class StudentsController extends AppController
{

    public function index()
    {

        // redirect to User controller if accessing index function
        return $this->redirect([
            'controller' => 'Users',
            'action' => 'index',
        ]);
    }

    public function edit($studentID)
    {
        $rightNow = new FrozenTime();

        // Check access and redirect 
        if (!$this->CheckAccess->accessCheck('App.Students',$studentID)) {
            $this->Flash->error('Unauthorized');
            return $this->redirect([
                'controller' => 'Users',
                'action' => 'index',
            ]);
        }

        
        try {
            // get student information 
            $student = $this->Students->get($studentID);
            
            //set student information for view
            $this->set('student', $student);

        } catch (RecordNotFoundException $e) {
            
            // redirect if student not found with get
            $this->Flash->error(__('Student not found'));
            return $this->redirect([
                'controller' => 'Users',
                'action' => 'index',
            ]);
        }

        // load StudentCourses Model
        $this->loadModel('StudentCourses');

        // call the findCurrentCourses function in StudentCoursesTable. 
        $studentCourses = $this->StudentCourses
            ->findCurrentCourses($studentID, Configure::read('App.currentYear')) // remember to UPDATE current year
        ;

        // set studentCourses for form view // consider set the vars AT THE END of edit()
        $this->set('studentCourses', $studentCourses);

        //////////////////////
        /// End form building / Start request handling
        ///////////////////////

        // declare arrays to hold unique error messages and confirmations
        $errors = [];
        $confirmations =[];

        // if there is request and it's not empty, process request
        if (
            ($this->getRequest()->is(['post', 'put']))
            and
            (!empty($this->getRequest()->getData()))
        ) {
            $postedData = $this->getRequest()->getData();

            // loop through the Answers array 
            foreach ($postedData['Answers'] as $id => $data) {

                // get the course as entity for updating 
                $course = $this->StudentCourses->get($id);

               
                // if receiveCredit is not answered - add error message to array
                if (!isset($data['receiveCredit'])) {

                    $errors[] = __("You did not select a 'yes/no' answer for one or more courses.");
                }

                // if receiveCredit is 'No' (0), adjust semesterGrade is 0
                if ($data['receiveCredit'] == '0') {

                    $data['semesterGrade'] = '0';
                }
               
                // if receiveCredit is 'Yes' but semesterGrade is empty - add error message to array
                if (
                    ($data['receiveCredit'] == '1')
                    &&
                    ($data['semesterGrade'] == null)  
                ) {
                    $errors[] = __("You answered 'yes' but did not specify a semester grade option.");

                }

                // build the array with the new form data
                $newData = [
                    'answer' => $data['semesterGrade'],
                    'guardianPersonID' => $this->session->read('Auth.personID'),
                    'modified' => $rightNow,
                ];

                // if there is no created date, enter the date now
                if (empty($course->created)) {
                
                    $newData['created'] = $rightNow;
                }

                // patch updated values for the entity
                $this->StudentCourses->patchEntity($course, $newData);

                $savedEntity = $this->StudentCourses->save($course);

                if ($savedEntity) {

                   $confirmations[] = $savedEntity;
                    
                } else {

                    $errors[] = __('Your information was not saved');

                }
                   
            } // end of foreach to loop through answers

            if (
                empty($errors) 
                && 
                (!empty($confirmations))
            ) {

                foreach ($confirmations as $confirmedCourse) {

                    // build messages that relate to answers
                    switch($confirmedCourse->answer){
                        case "0":
                            $confirmedCourse->answer = __("No High School credit requested");
                            break;
                        case "1":
                            $confirmedCourse->answer = __("High School credit requested if grade earned is A");
                            break;
                        case "2":
                            $confirmedCourse->answer = __("High School credit requested if grade earned is A or B");
                            break;
                        case "3":
                            $confirmedCourse->answer = __("High School credit requested if grade earned is A, B, or C");
                            break;
                        case "4":
                            $confirmedCourse->answer = __("High School credit requested if grade earned is A, B, C, or D");
                            break;

                    } // end of switch

                } //end of foreach confirmations as confirmation

                $emailTo = [];
                if (filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN)) {
                    $emailTo['maweiser@madison.k12.wi.us'] = 'Mike Weiser';
                } else {
                    if (!empty($answer->guardianEmail)) {
                        if (filter_var($answer->guardianEmail, FILTER_VALIDATE_EMAIL)) {
                            $emailTo[$answer->guardianEmail] = $this->Authentication->getIdentityData('fullName');
                        }
                    }
                }

                if (!empty($emailTo)) {
                    $email = new Mailer('enrollment');  
                    $email->setTo($emailTo);
                    $emailSubject = __('MMSD High School Credit Form');
                    if (filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN)) {
                        $emailSubject = "[TEST] {$emailSubject}";
                    }
                    $email->setSubject($emailSubject);
                    $email->setViewVars([
                        'student' => $student,
                        'confirmations' => $confirmations,
                    ]);
                    $email->viewBuilder()
                        ->setTemplate(I18n::getLocale() . '/Students/edit/email_all');
                    $email->deliver();
                } // end of if emailTo is not empty

                $this->Flash->success(__('Your information has been saved'));
                
                //redirect back to index (with student review/update option)
                return $this->redirect([
                    'controller' => 'Users',
                    'action' => 'index',
                ]);

            } else {
                // display the first error
                $this->Flash->error($errors[0]); 
         
            } 
                         
        } // end of if there's a request ...
        
    }// end of edit() function
    
}// end of Student Controller class
