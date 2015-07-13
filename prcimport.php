<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Block displaying information about current logged-in user.
 *
 * This block can be used as anti cheating measure, you
 * can easily check the logged-in user matches the person
 * operating the computer.
 *
 * @package    block_myprofile
 * @copyright  2010 Remote-Learner.net
 * @author     Olav Jordan <olav.jordan@remote-learner.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Displays the current user's profile information.
 *
 * @copyright  2015 Peterborough Regional College
 * @author     Jamie Homewood <jamie.homewood@peterborough.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', true);
include_once('../config.php');
require_once ('../course/lib.php');
require ('../enrol/meta/locallib.php');
require ('../enrol/cohort/locallib.php');

//FOR NORMAL FUNCTIONALITY, RUN THIS
start_import();

//DANGER, DANGER!! 
//IF USERS HAVE COMPLETED WORK AND HAVE BEEN ENROLLED USING COHORTS, 
//IT WILL ALL BE REMOVED
//USE WITH CAUTION, THINK BEFORE YOU JUMP!
//remove_all_cohorts(); 
//remove_cohort(55,1);
 
function start_import()
{
	$started = date('d-m-Y @ G:i:s');
	echo "script started: " . $started . PHP_EOL;
	
	//just getting data from csv files, not adding to database at this point
	$users = grab_users();		
	$courses = grab_courses();
	
	$userimport = create_users($users);
	
	//now get the top level courses.  These should be hidden from view
	$topcourses = create_topcourses($courses);
	
	//create cohorts, 
	//attach cohorts to main course($ in csv), 
	//fill cohorts with students who should be enrolled, 
	//create cohort enrolment linkage in db
	$cohorts = create_cohorts($users);
	attach_cohorts($topcourses,$cohorts);	
	fill_cohorts($userimport,$cohorts);		
	get_enrol_data($userimport,$topcourses,'cohort');
	
	//create taught units,
	//attach taught units (as meta) to main course($),
	//create meta enrollment linkage in db
	$units = create_units($courses);
	$units_with_extras = attach_meta($topcourses,$units);
	get_enrol_data($userimport,$units_with_extras,'meta');	
	
	$ended = date('d-m-Y @ G:i:s');
	echo "script started: " . $started . PHP_EOL;
	echo "script ended: " . $ended.PHP_EOL;
}

function grab_users()
{
	echo "grab_users started".PHP_EOL;
	//live csv		
	$file1="students.csv";
	$csv1= file_get_contents($file1);
	$users_array = array_map("str_getcsv", explode("\n", $csv1));
		
	echo "users found:".(count($users_array)-1).PHP_EOL;
	echo "grab_users complete".PHP_EOL;
	
	return $users_array;	
}

function grab_courses()
{		
	echo "grab_courses started".PHP_EOL;
	//test csv
	//$file1="units1.csv";
	
	//live csv	
	$file2="units.csv";
	$csv2= file_get_contents($file2);
	$course_array = array_map("str_getcsv", explode("\n", $csv2));		
	echo "courses & units found:".(count($course_array)-1).PHP_EOL;
	echo "grab_courses complete".PHP_EOL;
	return $course_array;
}

function create_users($users)
{
	global $CFG,$DB,$USER;
	echo "create_users started".PHP_EOL;
	
	$newusers = 0;
	
	$time = mktime(0, 0, 0, date("m") , date("d"), date("Y"));
	
	$user_count = (count($users)-1);
	for($u = 0; $u < $user_count; $u++)
	{
		///lets see if we already have this user
		/*$conn = mysql_connect($CFG->dbhost,$CFG->dbuser,$CFG->dbpass)or die('Error connecting to mySQL ' . mysql_error);
		mysql_select_db($CFG->dbname) or die (mysql_error());*/
		$user = $DB->get_record("user",array('username'=>strtolower($users[$u][0])));
		
		//if no user is found, then we need to add one
		if(empty($user))
		{									
			$users[$u][2] = remove_accents($users[$u][2]);
			$users[$u][3] = remove_accents($users[$u][3]);
			$users[$u][7] = remove_accents($users[$u][7]);
			//************************************************now create a user class************************************************
			$newuser = new stdClass();
			$newuser->auth			= 'manual'; 	//change to ldap when live
			$newuser->confirmed		= 0;
			$newuser->policyagreed	= 0;
			$newuser->deleted		= 0;
			$newuser->suspended		= 0;
			$newuser->mnethostid	= 1;       		//not sure what this is but stops the 'no such user' error!
			$newuser->username     	= strtolower($users[$u][0]);		// PRC id number
			$newuser->password		= '12345678';  	//will this change when ldap connected?
			$newuser->idnumber		= strtolower($users[$u][1]);		// PRC id number
			$newuser->firstname		= $users[$u][2];		
			$newuser->lastname		= $users[$u][3];
			$newuser->email			= $users[$u][4];		// will be PRC email
			$newuser->emailstop		= 0;
			$newuser->city			= 'PBORO';
			$newuser->country		= 'GB';
			$newuser->lang			= 'en';			
			$newuser->timecreated	= $time;
			$newuser->timemodified	= $time;
			
			$users[$u][8] = $DB->insert_record('user', $newuser, true);	
			
			$newusers++;		
		}
		else
		{				
			$users[$u][8] = $user->id;
		}
	}
	echo "new users imported: " . $newusers . PHP_EOL;
	echo "create_users ended".PHP_EOL;
	return $users;
}

function create_topcourses($courses)
{
	echo "create_topcourses started".PHP_EOL;
	global $CFG,$DB,$USER;
	$time = mktime(0, 0, 0, date("m") , date("d"), date("Y"));
	$topcourses = array();
	$offsetter = 0;
	$newtopcourses = 0;
	$dollars = 0;
	
	$coursecount = count($courses)-1;

	for($c = 0; $c < $coursecount; $c++)
	{
		//find any dollarcodes at the very top
		$get_dollar = substr($courses[$c][4],-1);	
		
		if($get_dollar == '$')
		{
			$topcourses[$offsetter][0] = $courses[$c][0];
			$topcourses[$offsetter][1] = $courses[$c][1];
			$topcourses[$offsetter][2] = $courses[$c][2];
			$topcourses[$offsetter][3] = $courses[$c][3];
			$topcourses[$offsetter][4] = $courses[$c][4];
			$topcourses[$offsetter][5] = $courses[$c][5];	
			
			/*
			within courses array			
			'shortname'=> $data[$i][0], 
			'idnumber' => $data[$i][0],
			'fullname' => $data[$i][1], 
			'category' => $data[$i][2],  
			'visible' =>  $data[$i][3], 
			'metacourse'=>$data[$i][4]				
			*/
		
			///lets see if we already have this course  
			/// idnumber must not change or another course will be created. 
			/// If a course is moved to a different category, this script will create a new course!				
			$course = $DB->get_record("course",array('idnumber'=>$courses[$c][0],'category'=>$courses[$c][2]));
				
			//if no course is found, then we need to add one
			if(empty($course))
			{				
				//************************now create a new course object******************
				$newcourse = new stdClass();
				$newcourse->shortname			= $topcourses[$offsetter][0];
				$newcourse->idnumber     		= $topcourses[$offsetter][0];
				$newcourse->fullname			= remove_accents($topcourses[$offsetter][1]);
				$newcourse->category			= $topcourses[$offsetter][2];
				$newcourse->visible				= $topcourses[$offsetter][3];
				$newcourse->format				= 'topics';
				$newcourse->startdate			= $time;
				$newcourse->timecreated			= $time;
				$newcourse->timemodified		= $time;
				$newcourse->enablecompletion	= 1;
				
				//add record to database
				$newcourse = create_course($newcourse);
				
				//nice to have the dollar code id
				$topcourses[$offsetter][6] = $newcourse->id;
				$offsetter++;
				$newtopcourses++;
			}	
			else
			{
				//nice to have the dollar code id
				$topcourses[$offsetter][6] = $course->id;
				$offsetter++;
			}
			$dollars++;
		}//end dollar check
		
	}// end loop through all courses
	echo "top courses found for import: " . $dollars . PHP_EOL;
	echo "top courses created: " . $newtopcourses . PHP_EOL;
	echo "create_topcourses ended".PHP_EOL;
	return $topcourses;
}

function create_cohorts($users)
{
	global $CFG,$DB,$USER;
	echo "create_cohorts started".PHP_EOL;
	
	$newcohorts = 0;
	
	$time = mktime(0, 0, 0, date("m") , date("d"), date("Y"));
	
	$usercount = count($users)-1;
	for($u = 0; $u < $usercount; $u++)
	{
		//first check if we already have this cohort			
		$reqcohort = $users[$u][6];		
		$cohort = $DB->get_record("cohort",array('idnumber'=>$reqcohort));
		
		//do we have one?			
		//no, ok lets add one here
		if(empty($cohort))
		{
			//*********************now create a new cohort*********
			$newcohort = new stdClass();
			$newcohort->contextid			= 1;
			$newcohort->name     			= $users[$u][7];
			$newcohort->idnumber			= $users[$u][6];
			$newcohort->visible				= 1;
			$newcohort->descriptionformat	= 1;
			$newcohort->timecreated			= $time;
			$newcohort->timemodified		= $time;
			
			//add it here
			$newcohort = $DB->insert_record('cohort', $newcohort, true);
			
			$cohorts[$u][0] = $newcohort;
			$cohorts[$u][1] = $reqcohort;
			
			$newcohorts++;
			echo "New Cohort:".$newcohorts.":".$cohorts[$u][1]. PHP_EOL;
		}
		else
		{
			//dont add one, but we may need the id and idnumber	
			$cohorts[$u][0] = $cohort->id;
			$cohorts[$u][1] = $reqcohort;
			//echo "Existing Cohort:".$cohorts[$u][1]."<br>";
		}
	}		
		
	echo "new cohorts created: " . $newcohorts . PHP_EOL;
	echo "create_cohorts ended".PHP_EOL;	
	return $cohorts;
}

function attach_cohorts($topcourses,$cohorts)
{
	global $CFG,$DB,$USER;
	echo "attach_cohorts started".PHP_EOL;
	
	$newattach = 0;
	
	$time = mktime(0, 0, 0, 
	date("m") , date("d"), date("Y"));

	$tccount = count($topcourses)-1;
	for($tc = 0; $tc <= $tccount; $tc++)
	{
		$cohcount = count($cohorts)-1;
		for($cc = 0; $cc < $cohcount; $cc++)
		{
			//echo $topcourses[$tc][4] .":". $cohorts[$cc][1]. PHP_EOL;
			//break;
			if($topcourses[$tc][4] == $cohorts[$cc][1])
			{
				//echo "found a match:".$topcourses[$tc][6] .":". $cohorts[$cc][0]. PHP_EOL;
				
				$ce = $DB->get_record("enrol",array('courseid'=>$topcourses[$tc][6], 'enrol'=>'cohort', 'customint1'=>$cohorts[$cc][0]));	
				
				if(empty($ce))
				{
					$newenroltype = new stdClass();
					$newenroltype->enrol			= 'cohort';
					$newenroltype->status     		= 0;
					$newenroltype->courseid			= $topcourses[$tc][6];
					$newenroltype->sortorder		= 4;
					$newenroltype->customint1		= $cohorts[$cc][0];
					$newenroltype->roleid			= 5;
					$newenroltype->timecreated		= $time;
					$newenroltype->timemodified		= $time;
							
					$DB->insert_record('enrol', $newenroltype, false);
					
					echo "new enrol created: " . $topcourses[$tc][6] . ":" . $cohorts[$cc][0].PHP_EOL;
					$newattach++;					
				}	
				/*else
				{
					echo "enrol exists: " . $topcourses[$tc][6] . ":" . $cohorts[$cc][0].PHP_EOL;
				}*/
			}
			
			
			
		}
	}
	echo "new cohorts attached:".$newattach.PHP_EOL;
	echo "attach_cohorts ended".PHP_EOL;
}

function fill_cohorts($users,$cohorts)
{
	global $CFG,$DB,$USER;
	echo "fill_cohorts started".PHP_EOL;
	
	$newmembers = 0;
	
	$time = mktime(0, 0, 0, 
	date("m") , date("d"), date("Y"));
	
	$ccount = count($cohorts)-1;
	for($cc = 0; $cc < $ccount; $cc++)
	{
		$ucount = count($users)-1;
		for($uc = 0; $uc < $ucount; $uc++)
		{
			if($cohorts[$cc][1] == $users[$uc][6])
			{
				//echo "Cohort : " . $cohorts[$cc][0] . ": Member :" . $users[$uc][8]. "<br>";
					
				$newcohortmember = new stdClass();
				$newcohortmember->cohortid			= $cohorts[$cc][0];
				$newcohortmember->userid     		= $users[$uc][8];
				$newcohortmember->timeadded			= $time;
				
				//firstcheck to see if we already have cohort member already
				$cohortmemberid = $DB->get_record("cohort_members",array('cohortid'=>$cohorts[$cc][0],'userid'=>$users[$uc][8]));
					
				//insert new cohort members if not found
				if(empty($cohortmemberid))
				{
					$cohortmember = $DB->insert_record('cohort_members', $newcohortmember, true);
					$newmembers++;										
				}					
			}
		}
	}
	echo "new members added:".$newmembers.PHP_EOL;
	echo "fill_cohorts ended".PHP_EOL;	
}

function get_enrol_data($users,$courses, $type)
{
	global $DB;
	echo "get_enrol_data started for ". $type ."enrolment, this may take some time...".PHP_EOL;
	$coursecount = count($courses);
	$usercount = count($users) - 1;

	for($c = 0; $c < $coursecount; $c++)
	{
		//echo "Course/Units to enrol to:".$courses[$c][1]. PHP_EOL;
		
		for($u = 0; $u <$usercount; $u++)
		{
			//this will need a check here!
			//if user is in this course then enrol them
			if($courses[$c][4] == $users[$u][6])
			{
				//cohort
				echo "we have a match: student:".$users[$u][6].":".$users[$u][8]. ":Course:" .$courses[$c][4].":".$courses[$c][6] . PHP_EOL;
				enrol_user($users[$u][8],$courses[$c][6],3,$type);
			}
			//stops an undefined error when enrolling in cohorts
			if($type == 'meta')
			{				
				if($courses[$c][7] == $users[$u][6])
				{
					//meta
					echo "we have a match: student:".$users[$u][6].":".$users[$u][8]. ":unit:" .$courses[$c][7].":".$courses[$c][6] . PHP_EOL;
					//$courses contain units this time
					enrol_user($users[$u][8],$courses[$c][6],3,$type);
				}	
			}
		}
	}
	echo "get_enrol_data complete for ". $type ." enrolment".PHP_EOL;
}

function enrol_user($userid, $course, $modifier,$type) 
{                                                
	global $DB;  
	$time = mktime(0, 0, 0, date("m") , date("d"), date("Y"));
	
	//echo "Enrolment data: ".$userid.":".$course.":".$modifier.":".$type."<br>";
																					 
	$enrolData = $DB->get_record('enrol', array('enrol'=>$type, 'courseid'=>$course)); 
	
	//if we have a course with a $type (cohort or meta) enrolment type, build a user_enrolment object
	if(!empty($enrolData))
	{     
		//quickly check we havent already got a user_enrolment link in place
		$ue = $DB->get_record('user_enrolments', array('enrolid'=>$enrolData->id, 'userid'=>$userid));
		
		//if we havent got one, lets create one
		if(empty($ue))
		{	
			$user_enrolment = new stdClass();                                                              
			$user_enrolment->enrolid = $enrolData->id;                                                 
			$user_enrolment->status = '0';                                                             
			$user_enrolment->userid = $userid;                                                         
			$user_enrolment->timestart = time();                                                       
			$user_enrolment->timeend =  '0';                                                           
			$user_enrolment->modifierid = $modifier;                                                   
			//Modifierid in this table is userid who enrolled this user manually (will be an admin)
			$user_enrolment->timecreated = time();                                                     
			$user_enrolment->timemodified = time(); 
		
			$insertId = $DB->insert_record('user_enrolments', $user_enrolment); 
			echo "new user_enrolments record created: " . $insertId .":". $enrolData->id . ":" . $userid.PHP_EOL;

			$context = $DB->get_record('context', array('contextlevel'=>50, 'instanceid'=>$course));  
			
			$role = new stdClass();                                                                        
			$role->roleid = 5;                                                                         
			$role->contextid = $context->id;                                                           
			$role->userid = $userid;                                                                   
			$role->component = '';                                                                     
			$role->itemid = 0;                                                                         
			$role->timemodified = time();                                                              
			$role->modifierid = $modifier;              

			$ra = $DB->get_record('role_assignments', array('contextid'=>$context->id, 'userid'=>$userid));

			if(empty($ra))
			{
				$insertId2 = $DB->insert_record('role_assignments', $role);                                    
				//add_to_log($course, '', $modifierid, 'automated'); 
				echo "new role_assignments record created: " . $insertId2 . ":" . $context->id . ":" . $userid . PHP_EOL;
			}
						   
			return array('user_enrolment'=>$insertId, 'role_assignment'=>$insertId2); 
		}
		else
		{
			echo "user enrolment checked: " . $enrolData->id . ":" . $userid.PHP_EOL;
		}
	}
						  
	//addto log                                                                              
}

function create_units($courses)
{
	echo "create_units started".PHP_EOL;
	
	global $CFG,$DB,$USER;
	$time = mktime(0, 0, 0, date("m") , date("d"), date("Y"));
	$units = array();
	$offsetter = 0;
	
	$newunits = 0;
	$notdollars = 0;
	
	$coursecount = count($courses)-1;

	for($c = 0; $c < $coursecount; $c++)
	{
		//find dollarcodes at the very top, we dont need to import them this time
		$get_dollar = substr($courses[$c][4],-1);	
		
		if($get_dollar != '$')
		{
			$units[$offsetter][0] = $courses[$c][0];
			$units[$offsetter][1] = $courses[$c][1];
			$units[$offsetter][2] = $courses[$c][2];
			$units[$offsetter][3] = $courses[$c][3];
			$units[$offsetter][4] = $courses[$c][4];
			$units[$offsetter][5] = $courses[$c][5];	
			
			/*
			within courses array			
			'shortname'=> $data[$i][0], 
			'idnumber' => $data[$i][0],
			'fullname' => $data[$i][1], 
			'category' => $data[$i][2],  
			'visible' =>  $data[$i][3], 
			'metacourse'=>$data[$i][4]				
			*/
		
			///lets see if we already have this course  
			/// idnumber must not change or another course will be created. 
			/// If a course is moved to a different category, this script will create a new course!				
			$course = $DB->get_record("course",array('idnumber'=>$courses[$c][0],'category'=>$courses[$c][2]));
				
			//if no course is found, then we need to add one
			if(empty($course))
			{				
				//************************now create a new course object******************
				$newunit = new stdClass();
				$newunit->shortname			= $units[$offsetter][0];
				$newunit->idnumber     		= $units[$offsetter][0];
				$newunit->fullname			= remove_accents($units[$offsetter][1]);
				$newunit->category			= $units[$offsetter][2];
				$newunit->visible			= $units[$offsetter][3];
				$newunit->format			= 'topics';
				$newunit->startdate			= $time;
				$newunit->timecreated		= $time;
				$newunit->timemodified		= $time;
				$newunit->enablecompletion	= 1;
				
				//add record to database
				$newunit = create_course($newunit);
				
				//nice to have the dollar code
				$units[$offsetter][6] = $newunit->id;
				echo "new unit created:". $units[$offsetter][0]. PHP_EOL;
				$offsetter++;
				$newunits++;					
			}	
			else
			{
				//nice to have the dollar code
				$units[$offsetter][6] = $course->id;
				$offsetter++;
			}
			$notdollars++;
			//echo "Units:".$units[$offsetter-1][4].$units[$offsetter-1][1].":".$units[$offsetter-1][6]."<br>";
		}//end dollar check
		
	}// end loop through all courses
	
	echo "units found for import: " . $notdollars . PHP_EOL;
	echo "units created: " . $newunits . PHP_EOL;
	echo "create_units ended".PHP_EOL;
	return $units;	
}
	
function attach_meta($topcourses,$units)
{
	global $CFG,$DB,$USER;
	$time = mktime(0, 0, 0, 
	date("m") , date("d"), date("Y"));
	
	$units_with_extras = array();
	
	echo "attach_meta started".PHP_EOL;	
	$newmetaattach = 0;
	
	$tccount = count($topcourses)-1;
	for($tc = 0; $tc <= $tccount; $tc++)
	{
		//echo $topcourses[$tc][6]."<br>";
		
		$ucount = count($units);
		for($u = 0; $u < $ucount; $u++)
		{
			if($units[$u][4] == $topcourses[$tc][0])
			{
				//echo $units[$u][0]."<br>";
				
				//I want this for the next function
				$units[$u][7] = $topcourses[$tc][4];
				
				$ce = $DB->get_records("enrol",array('courseid'=>$units[$u][6], 'enrol'=>'meta', 'customint1'=>$topcourses[$tc][6]));
				
				if(empty($ce))
				{
					//echo "Create new meta link:" . $topcourses[$tc][6] . ":" . $units[$u][6]."<br>";;
					
					$newenroltype = new stdClass();
					$newenroltype->enrol			= 'meta';
					$newenroltype->status     		= 0;
					$newenroltype->courseid			= $units[$u][6];
					$newenroltype->sortorder		= 4;
					$newenroltype->customint1		= $topcourses[$tc][6];
					$newenroltype->roleid			= 5;
					$newenroltype->timecreated		= $time;
					$newenroltype->timemodified		= $time;
							
					$DB->insert_record('enrol', $newenroltype, false);
					
					echo "new meta link created: " . $topcourses[$tc][6] . ":" . $units[$u][6].PHP_EOL;
					$newmetaattach++;					
				}
			}
		}
	}
	echo "new meta links attached:".$newmetaattach.PHP_EOL;
	echo "attach_meta ended".PHP_EOL;
	return $units_with_extras = $units;
}	

function remove_accents($string) 
{
    if ( !preg_match('/[\x80-\xff]/', $string) )
        return $string;

    $chars = array(
    // Decompositions for Latin-1 Supplement
    chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
    chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
    chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
    chr(195).chr(135) => 'C', chr(195).chr(136) => 'E',
    chr(195).chr(137) => 'E', chr(195).chr(138) => 'E',
    chr(195).chr(139) => 'E', chr(195).chr(140) => 'I',
    chr(195).chr(141) => 'I', chr(195).chr(142) => 'I',
    chr(195).chr(143) => 'I', chr(195).chr(145) => 'N',
    chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
    chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
    chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
    chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
    chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
    chr(195).chr(159) => 's', chr(195).chr(160) => 'a',
    chr(195).chr(161) => 'a', chr(195).chr(162) => 'a',
    chr(195).chr(163) => 'a', chr(195).chr(164) => 'a',
    chr(195).chr(165) => 'a', chr(195).chr(167) => 'c',
    chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
    chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
    chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
    chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
    chr(195).chr(177) => 'n', chr(195).chr(178) => 'o',
    chr(195).chr(179) => 'o', chr(195).chr(180) => 'o',
    chr(195).chr(181) => 'o', chr(195).chr(182) => 'o',
    chr(195).chr(182) => 'o', chr(195).chr(185) => 'u',
    chr(195).chr(186) => 'u', chr(195).chr(187) => 'u',
    chr(195).chr(188) => 'u', chr(195).chr(189) => 'y',
    chr(195).chr(191) => 'y',
    // Decompositions for Latin Extended-A
    chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
    chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
    chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
    chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
    chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
    chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
    chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
    chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
    chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
    chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
    chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
    chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
    chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
    chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
    chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
    chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
    chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
    chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
    chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
    chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
    chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
    chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
    chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
    chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
    chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
    chr(196).chr(178) => 'IJ',chr(196).chr(179) => 'ij',
    chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
    chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
    chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
    chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
    chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
    chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
    chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
    chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
    chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
    chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
    chr(197).chr(136) => 'n', chr(197).chr(137) => 'N',
    chr(197).chr(138) => 'n', chr(197).chr(139) => 'N',
    chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
    chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
    chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
    chr(197).chr(146) => 'OE',chr(197).chr(147) => 'oe',
    chr(197).chr(148) => 'R',chr(197).chr(149) => 'r',
    chr(197).chr(150) => 'R',chr(197).chr(151) => 'r',
    chr(197).chr(152) => 'R',chr(197).chr(153) => 'r',
    chr(197).chr(154) => 'S',chr(197).chr(155) => 's',
    chr(197).chr(156) => 'S',chr(197).chr(157) => 's',
    chr(197).chr(158) => 'S',chr(197).chr(159) => 's',
    chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
    chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
    chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
    chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
    chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
    chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
    chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
    chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
    chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
    chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
    chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
    chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
    chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
    chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
    chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
    chr(197).chr(190) => 'z', chr(197).chr(191) => 's'
    );

    $string = strtr($string, $chars);
	
	//quickly check for punctuation and remove any
	$string = preg_replace('/[^a-z]+/i', '', $string); 

    return $string;
}

function remove_all_cohorts()
{
	global $DB;
	
	$cohorts = $DB->get_records("cohort");
	
	foreach($cohorts as $c)
	{
		remove_cohort($c->id,1);
	}
	
	if(empty($cohorts))
	{
		echo "No Cohorts" . PHP_EOL;	
	}	
}

function remove_cohort($id,$delete)
{
	global $DB;
	
	if($id) 
	{
    	$cohort = $DB->get_record('cohort', array('id'=>$id));
		
		if(!empty($cohort))
		{
    		$context = context::instance_by_id($cohort->contextid);
		}
		
		if ($delete == 1) 
		{
			if(!empty($cohort))
			{
				cohort_delete_cohort($cohort);
				echo "Deleted Cohort:" . $cohort->id . PHP_EOL;
			}
			else
			{
				echo "No such cohort:" . $id . PHP_EOL;
			}
		}
	} 
	else 
	{
    	$context = context::instance_by_id($contextid);
    	if ($context->contextlevel != CONTEXT_COURSECAT and $context->contextlevel != CONTEXT_SYSTEM) 
		{
        	//print_error('invalidcontext');		
			echo "Invalid Context". PHP_EOL;
    	}
		
		$cohort = new stdClass();
		$cohort->id          = 0;
		$cohort->contextid   = $context->id;
		$cohort->name        = '';
		$cohort->description = '';
	}
}
