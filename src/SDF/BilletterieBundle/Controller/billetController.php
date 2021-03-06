<?php

namespace SDF\BilletterieBundle\Controller;

use SDF\BilletterieBundle\Entity\Evenement;
use SDF\BilletterieBundle\Entity\Tarif;
use SDF\BilletterieBundle\Entity\Navette;
use SDF\BilletterieBundle\Entity\Billet;
use SDF\BilletterieBundle\Entity\Trajet;
use SDF\BilletterieBundle\Entity\Utilisateur;
use SDF\BilletterieBundle\Entity\Contraintes;
use SDF\BilletterieBundle\Entity\Log;
use SDF\BilletterieBundle\Entity\Appkey;
use SDF\BilletterieBundle\Entity\UtilisateurExterieur;
use SDF\BilletterieBundle\Entity\UtilisateurCAS;
use SDF\BilletterieBundle\Entity\PotCommunTarifs;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\HttpFoundation\Request;
use SDF\BilletterieBundle\Form\TarifType;
use SDF\BilletterieBundle\Form\TrajetType;
use SDF\BilletterieBundle\Form\NavetteType;
use SDF\BilletterieBundle\Form\BilletType;
use SDF\BilletterieBundle\Form\PotCommunTarifsType;

use \Payutc\Client\AutoJsonClient;
use \Payutc\Client\JsonException;

class PDF extends \fpdf\FPDF {
    function EAN13($x, $y, $barcode, $h=16, $w=.35)
    {
       $this->Barcode($x, $y, $barcode, $h, $w, 13);
    }

    function UPC_A($x, $y, $barcode, $h=16, $w=.35)
    {
       $this->Barcode($x, $y, $barcode, $h, $w, 12);
    }

    function GetCheckDigit($barcode)
    {
       //Compute the check digit
       $sum=0;
       for($i=1;$i<=11;$i+=2)
           $sum+=3*$barcode{$i};
       for($i=0;$i<=10;$i+=2)
           $sum+=$barcode{$i};
       $r=$sum%10;
       if($r>0)
           $r=10-$r;
       return $r;
    }

    function TestCheckDigit($barcode)
    {
       //Test validity of check digit
       $sum=0;
       for($i=1;$i<=11;$i+=2)
           $sum+=3*$barcode{$i};
       for($i=0;$i<=10;$i+=2)
           $sum+=$barcode{$i};
       return ($sum+$barcode{12})%10==0;
    }

    function Barcode($x, $y, $barcode, $h, $w, $len)
    {
       //Padding
       $barcode=str_pad($barcode, $len-1, '0', STR_PAD_LEFT);
       if($len==12)
           $barcode='0'.$barcode;
       //Add or control the check digit
       if(strlen($barcode)==12)
           $barcode.=$this->GetCheckDigit($barcode);
       elseif(!$this->TestCheckDigit($barcode))
           $this->Error('Incorrect check digit');
       //Convert digits to bars
       $codes=array(
           'A'=>array(
               '0'=>'0001101', '1'=>'0011001', '2'=>'0010011', '3'=>'0111101', '4'=>'0100011',
               '5'=>'0110001', '6'=>'0101111', '7'=>'0111011', '8'=>'0110111', '9'=>'0001011'),
           'B'=>array(
               '0'=>'0100111', '1'=>'0110011', '2'=>'0011011', '3'=>'0100001', '4'=>'0011101',
               '5'=>'0111001', '6'=>'0000101', '7'=>'0010001', '8'=>'0001001', '9'=>'0010111'),
           'C'=>array(
               '0'=>'1110010', '1'=>'1100110', '2'=>'1101100', '3'=>'1000010', '4'=>'1011100',
               '5'=>'1001110', '6'=>'1010000', '7'=>'1000100', '8'=>'1001000', '9'=>'1110100')
           );
       $parities=array(
           '0'=>array('A', 'A', 'A', 'A', 'A', 'A'),
           '1'=>array('A', 'A', 'B', 'A', 'B', 'B'),
           '2'=>array('A', 'A', 'B', 'B', 'A', 'B'),
           '3'=>array('A', 'A', 'B', 'B', 'B', 'A'),
           '4'=>array('A', 'B', 'A', 'A', 'B', 'B'),
           '5'=>array('A', 'B', 'B', 'A', 'A', 'B'),
           '6'=>array('A', 'B', 'B', 'B', 'A', 'A'),
           '7'=>array('A', 'B', 'A', 'B', 'A', 'B'),
           '8'=>array('A', 'B', 'A', 'B', 'B', 'A'),
           '9'=>array('A', 'B', 'B', 'A', 'B', 'A')
           );
       $code='101';
       $p=$parities[$barcode{0}];
       for($i=1;$i<=6;$i++)
           $code.=$codes[$p[$i-1]][$barcode{$i}];
       $code.='01010';
       for($i=7;$i<=12;$i++)
           $code.=$codes['C'][$barcode{$i}];
       $code.='101';
       //Draw bars
       for($i=0;$i<strlen($code);$i++)
       {
           if($code{$i}=='1')
               $this->Rect($x+$i*$w, $y, $w, $h, 'F');
       }
       //Print text uder barcode
       $this->SetFont('arial', '', 12);
       $this->Text($x, $y+$h+11/$this->k, substr($barcode, -$len));
    }
    
    function Rotate($angle,$x=-1,$y=-1) { 

        if($x==-1) 
            $x=$this->x; 
        if($y==-1) 
            $y=$this->y; 
        if($this->angle!=0) 
            $this->_out('Q'); 
        $this->angle=$angle; 
        if($angle!=0) 

        { 
            $angle*=M_PI/180; 
            $c=cos($angle); 
            $s=sin($angle); 
            $cx=$x*$this->k; 
            $cy=($this->h-$y)*$this->k; 
             
            $this->_out(sprintf('q %.5f %.5f %.5f %.5f %.2f %.2f cm 1 0 0 1 %.2f %.2f cm',$c,$s,-$s,$c,$cx,$cy,-$cx,-$cy)); 
        } 
    } 
}

class NotFoundUserException extends \Exception {

}

class DeniedAccessException extends \Exception {

}

class EmptyStreamException extends \Exception {

}

class billetController extends Controller
{

  // INSERT YOUR PARAMETERS HERE :

    private $gingerKey = "";

    private $payutcAppKey = "";

    private $payutcFunID;


    private $PDOdatabase = "";

    private $PDOhost = "";

    private $user = '';
    private $password = '';

    private $email = ""; // l'email de l'asso

  // END OF PARAMETER INSERTION

    private function automatedJsonResponse($content){

      // TAKES AN ARRAY AS A PARAMETER

      $reponse = new Response();
      $reponse->headers->set('Content-Type','application/json');


      $reponse->setContent(json_encode($content));

      return $reponse;
    }

    private function instantLog($user,$content){
      $em = $this->getDoctrine()->getManager();

      $log = new Log();
      $log->setInstantLogAs($user,$content);

      $em->persist($log);
      $em->flush();
    }

    private function checkCASUserExists($login){

            $repositoryUserCAS = $this
            ->getDoctrine()
            ->getManager()
            ->getRepository('SDFBilletterieBundle:UtilisateurCAS');
            $userActif = $repositoryUserCAS->findOneBy(array('loginCAS' => $login));
            if(gettype($userActif) == "NULL") throw NotFoundUserException();
            $repositoryUserExt = $this
        ->getDoctrine()
        ->getManager()
        ->getRepository('SDFBilletterieBundle:UtilisateurCAS')
        ;
      $userActif = $repositoryUserExt->findOneBy(array('LoginCAS' => $login));
      if(gettype($userActif) == "NULL") throw NotFoundUserException();
    }

    private function checkExtUserExists($login){
      $repositoryUserExt = $this
        ->getDoctrine()
        ->getManager()
        ->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
        ;
      $userActif = $repositoryUserExt->findOneBy(array('login' => $login));
      if(gettype($userActif) == "NULL") throw NotFoundUserException();

    }

    private function checkUserExists(){
      // VERIFIE QU'IL Y A BIEN UN UTILISATEUR DE CONNECTE
      // SINON, JETTE UNE ERREUR NOTFOUNDUSEREXCEPTION

      $em = $this->getDoctrine()->getManager();
        //if ($_SESSION['typeUser'] == 'exterieur') return new Response("Connexion réussie pour l'utilisateur " . $_SESSION['user']->getLogin());
      // verifier que l'utilisateur est bien connecté
      //if (!checkConnexion($_SESSION)) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        if(session_id() == '') throw NotFoundUserException();
        if(!isset($_SESSION['user'])) throw NotFoundUserException();
        if($_SESSION['typeUser'] == 'exterieur'){
            checkExtUserExists($_SESSION['user']);
            // UTILISATEUR EXTERIEUR


        } elseif ($_SESSION['typeUser'] == 'cas') {
            checkCASUserExists($_SESSION['user']);
            // UTILISATEUR CAS


        } else {
          throw NotFoundUserException();
        }
        
    }

    private function checkConsultationRights($userID, $billetID){
      /* VERIFIE QUE L'UTILISATEUR ACTIF D'ID $userID A BIEN ACCES A CE BILLET $billetID
          SINON, JETTE UNE ERREUR DENIEDACCESSEXCEPTION
      */
        $em=$this->getDoctrine()->getManager();
        $repoBillet = $em->getRepository('SDFBilletterieBundle:Billet');
        $repoUser = $em->getRepository('SDFBilletterieBundle:Utilisateur');
        $billet = $repoBillet->find($id);

        // on vérifie les accès
        if (gettype($billet) == 'NULL' || $billet->getUtilisateur()->getId() != $userRefActif->getId()){
            $utilisateur = $repoUser->find($userID);
            if (!$utilisateur->getAdmin()) throw DeniedAccessException();
        }
    }


    private function listeBilletsFetchQueryGeneration($userID){
        $em = $this->getDoctrine()->getManager();

        $repoTarifs = $em->getRepository('SDFBilletterieBundle:Tarif');

        if($_SESSION['typeUser'] == 'cas' && $userActif->getCotisant()) $accesTarifsCotisants ='';
        else $accesTarifsCotisants = ' c.doitEtreCotisant = FALSE AND';

        if($_SESSION['typeUser'] == 'cas' && !($userActif->getCotisant())) $accesTarifsNonCotisant = '';
        else $accesTarifsNonCotisant = ' c.doitNePasEtreCotisant = FALSE AND';

        if($_SESSION['typeUser'] == 'exterieur') $necessaireExterieur = ' c.accessibleExterieur = TRUE AND';
        else $necessaireExterieur = '';

        $query = $em->createQuery('SELECT p.id AS idPot FROM SDFBilletterieBundle:Billet b JOIN b.tarif t JOIN t.potCommun p JOIN b.utilisateur u WHERE u.id = :id AND b.valide = TRUE AND t.potCommun IS NOT NULL');
        $query->setParameter('id',$userID);
        $resultatRequetePotsCommunsUtilises = $query->getResult();

        $requetePotsCommuns = "";

        foreach($resultatRequetePotsCommunsUtilises as $potCommun){
            if($requetePotsCommuns == "") $requetePotsCommuns .= (" AND (p IS NULL OR p.id != " . $potCommun['idPot']);
            else $requetePotsCommuns .= (" AND p.id != " . $potCommun['idPot']);
        }
        if($requetePotsCommuns != "") $requetePotsCommuns .= ")";

        $query = $em->createQuery('SELECT t FROM SDFBilletterieBundle:Tarif t JOIN t.contraintes c JOIN t.evenement e LEFT JOIN t.potCommun p WHERE
            ' . $accesTarifsCotisants . $accesTarifsNonCotisant . $necessaireExterieur . ' c.debutMiseEnVente < :dateAJD AND c.finMiseEnVente > :dateAJD' . $requetePotsCommuns);
        $dateString = date_format(date_create(),'Y-m-d H:i:s');
        $query->setParameter('dateAJD',$dateString);

        return $query;
    }

    private function checkBilletAvailable($tarifID){
      /* RETURNS TRUE IF AVAILABLE

      RETURNS FALSE OTHERWISE */

      $em = $this->getDoctrine()->getManager();
      $repoTarifs = $em->getRepository('SDFBilletterieBundle:Tarif');
      $billetDispo = $repoTarifs->find($tarifID);

      // pour chaque tarif, on veut obtenir le nombre de billets dispos restant pour cette personne

      $nbBilletDeCeTarif = count($em
          ->createQuery('SELECT b FROM SDFBilletterieBundle:Billet b JOIN b.utilisateur u JOIN b.tarif t WHERE u.id = :id AND t.id = :idTarif')
          ->setParameter('id',$userRefActif->getId())
          ->setParameter('idTarif',$billetDispo->getId())
          ->getResult());

      $qteRestante = $billetDispo->getQuantiteParPersonne() - $nbBilletDeCeTarif;

      // on veut ensuite obtenir le nombre de billets déjà achetés par tout le monde

      $query = $em
          ->createQuery('SELECT COUNT(b) AS c FROM SDFBilletterieBundle:Billet b JOIN b.tarif t WHERE t.id = :idTarif')
          ->setParameter('idTarif',$billetDispo->getId())
          ->getResult();

      $qteRestanteGlobale = $billetDispo->getQuantite() - $query[0]['c'];

      // on veut enfin obtenir le nombre de billets achetés correspondant à l'évènement

      $query = $em
              ->createQuery('SELECT COUNT(b) AS c FROM SDFBilletterieBundle:Billet b JOIN b.tarif t JOIN t.evenement e WHERE e.id = :idEvent')
              ->setParameter('idEvent',$billetDispo->getEvenement()->getId())
              ->getResult();

      $qteRestanteEvent = $billetDispo->getEvenement()->getQuantiteMax() - $query[0]['c'];

      // on veut également vérifier que le pot commun n'a pas été consommé
      if (gettype($billetDispo->getPotCommun()) != 'NULL') {
      $query = $em->createQuery('SELECT COUNT(b) AS c FROM SDFBilletterieBundle:Billet b JOIN b.utilisateur u JOIN b.tarif t JOIN t.potCommun p WHERE u.id = :id AND p.id = :idPot')
              ->setParameter('id',$userRefActif->getId())
              ->setParameter('idPot',$billetDispo->getPotCommun()->getId())
              ->getResult();

      $potCommunNonConsomme = ($query[0]['c'] < 1); } else $potCommunNonConsomme = true;

      if ($qteRestante > 0
        && $qteRestanteGlobale > 0
        && $qteRestanteEvent > 0
        && $potCommunNonConsomme) 
        return true;
      else return false;
    }

    private function getListeBilletsDispos($userID){
      /* RETOURNE UN ARRAY AVEC LA LISTE DES BILLETS DISPOS
      AVEC CES ATTRIBUTS :
        - nom
        - prix
        - quantiterestante
        - id
        */

        $query = listeBilletsFetchQueryGeneration($userID);

        $resultatRequeteBilletsDispos = $query->getResult();

        foreach($resultatRequeteBilletsDispos as $billetDispo){

            if(checkBilletAvailable($billetDispo->getId())) {
              $listeBilletsDispos[] = Array(
              'nom' => $billetDispo->getNomTarif(),
              'prix' => $billetDispo->getPrix(),
              'quantiteRestante' => min($qteRestante,$qteRestanteGlobale,$qteRestanteEvent),
              'id' => $billetDispo->getId()
              );
            }
        }

        return $listeBilletsDispos;
    }

    private function getAssociatedBillets($id){
      /*
        RETOURNE UN ARRAY AVEC LES BILLETS D'UN USER
        AVEC COMME ATTRIBUTS :
          - NOM : NOM DU POSSESSEUR
          - TYPE : TARIF DU BILLET
          - NAVETTE : INDIQUE S'IL Y A NAVETTE
          - HORAIRENAVETTE : L'HORAIRE DE LA NAVETET
          - DEPARTNAVETTE : LE LIEU DE DEPART
          - ID : L'ID DU BILLET
      */

        $listeBilletsAchetes = Array();
        $repoBillets = $this->getDoctrine()->getManager()
          ->getRepository('SDFBilletterieBundle:Billet');

        $query = $em->createQuery('SELECT b FROM SDFBilletterieBundle:Billet b JOIN b.utilisateur u WHERE u.id = :id AND b.valide = TRUE');
        $query->setParameter('id',$id);
        $resultatRequeteBilletsAchetes = $query->getResult();

        $i = 0;

        foreach($resultatRequeteBilletsAchetes as $billetAchete){
            if(gettype($billetAchete->getNavette()) != 'NULL') {
                $listeBilletsAchetes[$i] = Array(
                    'nom' => $billetAchete->getPrenom() . ' ' . $billetAchete->getNom(),
                    'type' => $billetAchete->getTarif()->getNomTarif(),
                    'navette' => true,
                    'horaireNavette' => $billetAchete->getNavette()->getHoraireDepartFormat(),
                    'departNavette' => $billetAchete->getNavette()->getTrajet()->getLieuDepart(),
                    'id' => $billetAchete->getId()
                    );
            } else {
                $listeBilletsAchetes[$i] = Array(
                    'nom' => $billetAchete->getPrenom() . ' ' . $billetAchete->getNom(),
                    'type' => $billetAchete->getTarif()->getNomTarif(),
                    'navette' => false,
                    'id' => $billetAchete->getId()
                    );
            }
            $i++;
        }

        return $listeBilletsAchetes;
    }

    private function checkIfInvalidBillet($userID){

      $em = $this->getDoctrine()->getManager();
      /* RETURNS FALSE IF NO INVALID BILLET

      ELSE RETURNS THE ID */

      $query = $em->createQuery('SELECT b FROM SDFBilletterieBundle:Billet b JOIN b.utilisateur u WHERE u.id = :id AND b.valide = FALSE');
        $query->setParameter('id',$userRefActif->getId());
        $resultatRequeteBilletsInvalides = $query->getResult();

      if (count($resultatRequeteBilletsInvalides) > 0){
        foreach($resultatRequeteBilletsInvalides as $billet){
          return $billet->getId();
        }
      } else return false;
    }

    public function listeBilletsAction($message = false)
    {
        try {
          checkUserExists();
        } catch (NotFoundUserException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        }
        if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
              ->findOneBy(array('loginCAS' => $login));
        else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
              ->findOneBy(array('login' => $login));
        $userRefActif = $userActif->getUser();
        $nomUserActif = $userRefActif->getPrenom() . ' ' . $userRefActif->getNom();

        /*
        ON VEUT COMMENCER PAR RECUPERER LES BILLETS ACHETES
        */

        $listeBilletsAchetes = getAssociatedBillets($userRefActif->getId());

        /*
        ON VEUT ENSUITE RECUPERER LES BILLETS DISPONIBLES
        */

        $listeBilletsDispos = getListeBilletsDispos($userRefActif->getId());

        /* ON RECUPERE LES BILLETS NON VALIDÉS */

        $billetInvalide = checkIfInvalidBillet($userRefActif->getId());
        $isBilletInvalide = ($billetInvalide === false) ? true : false;

        /*        ON AGREGE ENSUITE TOUT CELA DANS LA VUE        */

        if (count($listeBilletsAchetes) == 0) $listeBilletsAchetes = 0;
        if (count($listeBilletsDispos) == 0) $listeBilletsDispos = 0;

        return $this->render('SDFBilletterieBundle:billet:listebillets.html.twig', array('message' => $message, 'billetInvalide' => $isBilletInvalide, 'billetNonValide' => $billetInvalide, 'billetsAchetes' => $listeBilletsAchetes, 'billetsDispos' => $listeBilletsDispos, 'nomUtilisateur' => $nomUserActif));

    }

    private function checkUserIsAdmin(){
      // RETOURNE TRUE SI L'USER EST ADMIN
      // RETOURNE FALSE S'IL N'Y A PAS D'USER CONNECTÉ OU S'IL N'EST PAS ADMIN
        try {
          checkUserExists();
        } catch (NotFoundUserException $e) {
          return false;
        }
        if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
              ->findOneBy(array('loginCAS' => $login));
        else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
              ->findOneBy(array('login' => $login));
        $userRefActif = $userActif->getUser();
        if (!($userRefActif->getAdmin())) return false;
        else return true;
    }

    public function checkContraintesAction(Request $request){

        // ON VERIFIE QUE L'UTILISATEUR EXISTE & EST ADMIN
        if (!checkUserIsAdmin()) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        

        $event = new Contraintes();
        $formBuilder = $this->get('form.factory')->createBuilder('form', $event);
        $formBuilder
          ->add('nom',      'text')
          ->add('doitEtreCotisant',     'checkbox')
          ->add('doitNePasEtreCotisant',     'checkbox')
          ->add('accessibleExterieur', 'checkbox')
          ->add('debutMiseEnVente', 'datetime')
          ->add('finMiseEnVente', 'datetime')
      ->add('save',      'submit')
        ;
        $form = $formBuilder->getForm();

        $form->handleRequest($request);
        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($event);
            $em->flush();

            return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "set de contraintes", 'addError' => false, 'addOK' => true
        ));
        }


        return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "set de contraintes", 'addError' => false, 'addOK' => false
        ));
    }

    public function checkEventAction(Request $request){

        // ON VERIFIE QUE L'UTILISATEUR EXISTE & EST ADMIN
        if (!checkUserIsAdmin()) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));

        $event = new Evenement();
        $formBuilder = $this->get('form.factory')->createBuilder('form', $event);
        $formBuilder
          ->add('nom',      'text')
          ->add('quantiteMax',     'text')
      ->add('save',      'submit')
        ;
        $form = $formBuilder->getForm();

        $form->handleRequest($request);
        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->persist($event);
            $em->flush();

            return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "évènement", 'addError' => false, 'addOK' => true
        ));
        }


        return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "évènement", 'addError' => false, 'addOK' => false
        ));
    }

    public function tarifsAction(Request $request){

        // ON VERIFIE QUE L'UTILISATEUR EXISTE & EST ADMIN
        if (!checkUserIsAdmin()) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));

        $tarif = new Tarif();

        $form = $this->get('form.factory')->create(new TarifType, $tarif);

        if($form->handleRequest($request)->isValid()){
            $em = $this->getDoctrine()->getManager();
            $em->persist($tarif);
            $em->flush();

            return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "tarif", 'addError' => false, 'addOK' => true
        ));
        
        }

        return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "tarif", 'addError' => false, 'addOK' => false
        ));

    }

    public function checkTrajetsNavetteAction(Request $request){

        // ON VERIFIE QUE L'UTILISATEUR EXISTE & EST ADMIN
        if (!checkUserIsAdmin()) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));

        $tarif = new Trajet();

        $form = $this->get('form.factory')->create(new TrajetType, $tarif);

        if($form->handleRequest($request)->isValid()){
            $em = $this->getDoctrine()->getManager();
            $em->persist($tarif);
            $em->flush();

            return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "trajet", 'addError' => false, 'addOK' => true
        ));
        
        }

        return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "trajet", 'addError' => false, 'addOK' => false
        ));
    }

    public function checkNavettesAction(Request $request){

        // ON VERIFIE QUE L'UTILISATEUR EXISTE & EST ADMIN
        if (!checkUserIsAdmin()) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));

        $tarif = new Navette();

        $form = $this->get('form.factory')->create(new NavetteType, $tarif);

        if($form->handleRequest($request)->isValid()){
            $em = $this->getDoctrine()->getManager();
            $em->persist($tarif);
            $em->flush();

            return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "navette", 'addError' => false, 'addOK' => true
        ));
        
        }

        return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "navette", 'addError' => false, 'addOK' => false
        ));
    }


    public function checkPotCommunAction(Request $request){

        // ON VERIFIE QUE L'UTILISATEUR EXISTE & EST ADMIN
        if (!checkUserIsAdmin()) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));

        $pot = new PotCommunTarifs();

        $form = $this->get('form.factory')->create(new PotCommunTarifs, $tarif);

        if($form->handleRequest($request)->isValid()){
            $em = $this->getDoctrine()->getManager();
            $em->persist($tarif);
            $em->flush();

            return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "pot commun", 'addError' => false, 'addOK' => true
        ));
        
        }

        return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "pot commun", 'addError' => false, 'addOK' => false
        ));
    }

    public function billetAdminAction(Request $request){

        // ON VERIFIE QUE L'UTILISATEUR EXISTE & EST ADMIN
        if (!checkUserIsAdmin()) return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));

        $tarif = new Billet();

        $form = $this->get('form.factory')->create(new BilletType, $tarif);

        if($form->handleRequest($request)->isValid()){
            $em = $this->getDoctrine()->getManager();
            $em->persist($tarif);
            $em->flush();

            return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "billet", 'addError' => false, 'addOK' => true
        ));
        
        }

        return $this->render('SDFBilletterieBundle:billet:add.html.twig', array(
          'form' => $form->createView(),'name' => "billet", 'addError' => false, 'addOK' => false
        ));
    }

    public function paramBilletAction($id){

        /*        ON COMMENCE PAR VÉRIFIER LA CONNEXION        */

        try {
          checkUserExists();
          if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
                ->findOneBy(array('loginCAS' => $login));
          else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
                ->findOneBy(array('login' => $login));
          $userRefActif = $userActif->getUser();
          /* LA CONNEXION EST VÉRIFIÉE        ON VÉRIFIE LES ACCÈS AU BILLET */
          checkConsultationRights($userRefActif->getId(),$id);
        } catch (NotFoundUserException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        } catch (DeniedAccessException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',
            array('message'=>'accessBillet')));
        }

        /* LISTE DES VARIABLES TWIG :

        - billetID
        - typeBillet
        - nomBillet
        - prenomSurLeBillet
        - noNavetteSelected
        - navettes, composées d'arrays avec :
            - idNavette
            - desactivee
            - navetteSelectionnee
            - lieuDepart
            - horaireNavette
            - placesRestantes

        */

        $typeBillet = $billet->getTarif()->getNomTarif();
        $nomBillet = $billet->getNom();
        if (gettype($billet->getNavette()) == 'NULL') $noNavetteSelected = true;
        else $noNavetteSelected = false;
        $prenomSurLeBillet = $billet->getPrenom();

        $arrayToutesNavettes = $repoNavettes->findAll();
        $tabNavettes = Array();
        foreach($arrayToutesNavettes as $navetteEtudiee){
            $enregNavette = Array();
            $enregNavette['idNavette'] = $navetteEtudiee->getId();
            $enregNavette['lieuDepart'] = $navetteEtudiee->getTrajet()->getLieuDepart();
            $enregNavette['horaireNavette'] = $navetteEtudiee->getHoraireDepartFormat();
            if(!$noNavetteSelected && ($billet->getNavette()->getId() == $navetteEtudiee->getId())){
                $enregNavette['navetteSelectionnee'] = true;
            } else {
                $enregNavette['navetteSelectionnee'] = false;
            }
            $requete = $em->createQuery('SELECT COUNT(b) AS c FROM SDFBilletterieBundle:Billet b JOIN b.navette n WHERE n.id = :idNavette')
                ->setParameter('idNavette',$navetteEtudiee->getId())
                ->getResult();
            if ($requete[0]['c'] < $navetteEtudiee->getCapaciteMax()){
                $enregNavette['desactivee'] = false;
            } else {
                $enregNavette['desactivee'] = true;
            }
            if (gettype($billet->getNavette()) != 'NULL' &&$navetteEtudiee->getId() == $billet->getNavette()->getId()) $enregNavette['desactivee'] = false;
            $enregNavette['placesRestantes'] = - ($requete[0]['c'] - $navetteEtudiee->getCapaciteMax());
            $tabNavettes[] = $enregNavette;
        }

        return $this->render('SDFBilletterieBundle:billet:paramBillet.html.twig', array(
          'billetID' => $billet->getId(),
          'typeBillet' => $typeBillet,
          'nomBillet' => $nomBillet,
          'prenomBillet' => $prenomSurLeBillet,
          'noNavetteSelected' => $noNavetteSelected,
          'navettes' => $tabNavettes
        ));
    }

    public function changedParamBilletAction($id){

        /*

        ON COMMENCE PAR VÉRIFIER LA CONNEXION

        */

        try {
          checkUserExists();
          if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
                ->findOneBy(array('loginCAS' => $login));
          else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
                ->findOneBy(array('login' => $login));
          $userRefActif = $userActif->getUser();
          /* LA CONNEXION EST VÉRIFIÉE        ON VÉRIFIE LES ACCÈS AU BILLET */
          checkConsultationRights($userRefActif->getId(),$id);
        } catch (NotFoundUserException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        } catch (DeniedAccessException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',
            array('message'=>'accessBillet')));
        }

        /* ACCÈS AU BILLET VÉRIFIÉ

        ON FAIT MAINTENANT L'ACTION VOULUE */

        if ($_POST['nom'] != '') $billet->setNom($_POST['nom']);
        if ($_POST['prenom'] != '') $billet->setPrenom($_POST['prenom']);

        if ($_POST['sel1'] == 'noNavette') {
            //il faut mettre la navette à null pour le billet étudié

            $qb = $em->createQueryBuilder();
            $qb->update('SDFBilletterieBundle:Billet','billet');

            $qb->set('billet.navette',':navettenull');
            $qb->setParameter('navettenull',null);

            $qb->where('billet.id = :id');
            $qb->setParameter('id',$id);

            $qb->getQuery()->execute();
        }
        else {
            if (gettype($repoNavettes->find($_POST['sel1'])) != 'NULL'){
                // navette valide, on vérifie qu'il y a assez de place
                $requete = $em->createQuery('SELECT COUNT(b) AS c FROM SDFBilletterieBundle:Billet b JOIN b.navette n WHERE n.id = :idNavette')
                ->setParameter('idNavette',$_POST['sel1'])
                ->getResult();
                if ($requete[0]['c'] < $repoNavettes->find($_POST['sel1']) || $billet->getNavette()->getId() == $_POST['sel1']){
                    // PLACE VALIDE
                    // ON ATTRIBUE LA PLACE DANS LA NAVETTE
                    $billet->setNavette($repoNavettes->find($_POST['sel1']));
                } else {
                    return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'savingOptionsError')));
                }
            } else {
                return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'savingOptionsError')));
            }
        }

        $em->persist($billet);
        $em->flush();

        return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'savingOptionsSuccess')));

    }

    private function pdfGeneration($userPrenom,$userNom,$nomTarif,$billetID,
      $tarifPrix,$billetNom,$billetPrenom,$billetBarcode){

        $pdf = new PDF();

        $pdf->Open();
        $pdf->AddPage('L');
        $pdf->SetAutoPageBreak(true,'5');
        $adresseRawBillet = __DIR__ . '/../Resources/images/rawBillet.jpg';
        $pdf->Image($adresseRawBillet,0,0,297,210);
        $pdf->SetFont('arial','B','20');
        $pdf->SetTextColor(0,0,0);
        $pdf->SetXY(174,13+11);
        $pdf->Write(10,iconv("UTF-8", "ISO-8859-1",ucfirst(strtolower($billetPrenom))));
        $pdf->SetXY(174,21+11);
        $pdf->Write(10,iconv("UTF-8", "ISO-8859-1",strtoupper($billetNom)));
        $pdf->SetFont('arial','','20');
        $pdf->SetXY(174,42+11);
        $pdf->Write(10,iconv("UTF-8", "ISO-8859-1",strtoupper($nomTarif)));

        $pdf->SetTextColor(0,0,0);
        $pdf->SetFont('arial','','11');
        $pdf->SetXY(174,6+11);
        $pdf->Write(10,"Num".chr(233)."ro de billet : ".$billetID);

        $pdf->SetXY(174,32+11);
        //$pdf->SetFont('times','','30');
        $pdf->SetTextColor(0,0,0);
        $pdf->Write(10, "Prix TTC : " . $tarifPrix . ' '.chr(128));

        $pdf->SetXY(174,58+11);
        $pdf->Write(10,"Billet achet".chr(233)." par : ".iconv("UTF-8", "ISO-8859-1", strtoupper($userNom)." ".ucfirst($userPrenom)));
        $pdf->SetTextColor(0,0,0);
        $pdf->EAN13(174, 72+11, $billetBarcode, 12, 1);

        return $pdf->Output('','I');

    }

    public function accessBilletAction($id){

        /*

        ON COMMENCE PAR VÉRIFIER LA CONNEXION

        */

        try {
          checkUserExists();
          if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
                ->findOneBy(array('loginCAS' => $login));
          else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
                ->findOneBy(array('login' => $login));
          $userRefActif = $userActif->getUser();
          /* LA CONNEXION EST VÉRIFIÉE        ON VÉRIFIE LES ACCÈS AU BILLET */
          checkConsultationRights($userRefActif->getId(),$id);
        } catch (NotFoundUserException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        } catch (DeniedAccessException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',
            array('message'=>'accessBillet')));
        }

        /* ACCÈS AU BILLET VÉRIFIÉ
    
        ON FAIT MAINTENANT L'ACTION VOULUE */

        $pdf = pdfGeneration($billet->getUtilisateur()->getPrenom(),
          $billet->getUtilisateur()->getNom(),
          $billet->getTarif()->getNomTarif(),
          $billet->getId(),
          $billet->getTarif()->getPrix(),
          $billet->getNom(),
          $billet->getPrenom(),
          $billet->getBarcode());

        $reponse = new Response();
        $reponse->headers->set('Content-Type', 'application/pdf');

        $reponse->setContent($pdf);

        return $reponse;
    }

    public function buyBilletAction($typeBillet){
        
        /*        ON COMMENCE PAR VÉRIFIER LA CONNEXION        */

        try {
          checkUserExists();
          if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
                ->findOneBy(array('loginCAS' => $login));
          else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
                ->findOneBy(array('login' => $login));
          $userRefActif = $userActif->getUser();
          /* LA CONNEXION EST VÉRIFIÉE        ON VÉRIFIE LES ACCÈS AU BILLET */
          if (!checkBilletAvailable($typeBillet)) return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
        } catch (NotFoundUserException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        } 

        /*

            ACCES AU BILLET VERIFIE

        */

        $repoNavettes = $em->getRepository('SDFBilletterieBundle:Navette');

        $arrayToutesNavettes = $repoNavettes->findAll();
        $tabNavettes = Array();
        foreach($arrayToutesNavettes as $navetteEtudiee){
            $enregNavette = Array();
            $enregNavette['idNavette'] = $navetteEtudiee->getId();
            $enregNavette['lieuDepart'] = $navetteEtudiee->getTrajet()->getLieuDepart();
            $enregNavette['horaireNavette'] = $navetteEtudiee->getHoraireDepartFormat();
            $requete = $em->createQuery('SELECT COUNT(b) AS c FROM SDFBilletterieBundle:Billet b JOIN b.navette n WHERE n.id = :idNavette')
                ->setParameter('idNavette',$navetteEtudiee->getId())
                ->getResult();
            if ($requete[0]['c'] < $navetteEtudiee->getCapaciteMax()){
                $enregNavette['desactivee'] = false;
            } else {
                $enregNavette['desactivee'] = true;
            }
            $enregNavette['placesRestantes'] = - ($requete[0]['c'] - $navetteEtudiee->getCapaciteMax());
            $tabNavettes[] = $enregNavette;
        } 

        return $this->render('SDFBilletterieBundle:billet:achatbillet.html.twig', array(
          'billetID' => $billetDispo->getId(),
          'typeBillet' => $billetDispo->getNomTarif(),
          'prixBillet' => $billetDispo->getPrix(),
          'navettes' => $tabNavettes
        ));
    }

    public function payUTCcallbackAction($token){
        /*

        ON COMMENCE PAR VÉRIFIER LA CONNEXION

        */

        /*        ON COMMENCE PAR VÉRIFIER LA CONNEXION        */

        try {
          checkUserExists();
          if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
                ->findOneBy(array('loginCAS' => $login));
          else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
                ->findOneBy(array('login' => $login));
          $userRefActif = $userActif->getUser();
          /* LA CONNEXION EST VÉRIFIÉE        ON VÉRIFIE LES ACCÈS AU BILLET */
          if (!checkBilletAvailable($token)) return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
        } catch (NotFoundUserException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        } 

        /*

            ACCES AU BILLET VERIFIE

        */

            /* On vérifie maintenant les données */

            if (!isset($_POST['nom']) || $_POST['nom'] == ''){
                return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
            }
            if (!isset($_POST['prenom']) || $_POST['prenom'] == ''){
                return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
            }


            $repoNavettes = $em->getRepository('SDFBilletterieBundle:Navette');
            if (isset($_POST['sel1']) && $_POST['sel1'] = 'noNavette') $navetteChoisie = 'noNavette';
            else {
                if (isset($_POST['sel1']) && gettype($em->getRepository('SDFBilletterieBundle:Navette')->find($_POST['sel1'])) == 'NULL'){
                    // Navette définie, et existe : on vérifie qu'il y a assez de place
                    $requete = $em->createQuery('SELECT COUNT(b) AS c FROM SDFBilletterieBundle:Billet b JOIN b.navette n WHERE n.id = :idNavette')
                    ->setParameter('idNavette',$_POST['sel1'])
                    ->getResult();
                    if ($requete[0]['c'] < $repoNavettes->find($_POST['sel1'])){
                        // PLACE VALIDE
                        // ON ATTRIBUE LA PLACE DANS LA NAVETTE
                        $navetteChoisie = $_POST['sel1'];
                        // OK
                    } else {
                        return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
                    }

                } else {
                    return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
                }
            }

            /* OK DONNEES VERIFIEES */

            /* On passe à la génération du billet */

            $billetCree = new Billet();
            $billetCree->setValide(false);
            $billetCree->setIdPayutc('');
            $billetCree->setNom($_POST['nom']);
            $billetCree->setPrenom($_POST['prenom']);
            $billetCree->setIsMajeur(true);
            $billetCree->setConsomme(false);
            $billetCree->setDateAchat(new \DateTime());
            $notOK = true;
            while($notOK){
                $barcode = rand(0,1000000000);
                if(gettype($em->getRepository('SDFBilletterieBundle:Billet')->findOneBy(array('barcode' => $barcode))) == 'NULL') $notOK = false;
            }
            $billetCree->setBarcode($barcode);
            if(isset($_POST['droitimage']) && $_POST['droitimage'] == '1'){
                $billetCree->setAccepteDroitImage(true);
            } else{
                $billetCree->setAccepteDroitImage(false);
            }
            if($navetteChoisie != 'noNavette') $billetCree->setNavette($em->getRepository('SDFBilletterieBundle:Navette')->find($navetteChoisie));
            $billetCree->setTarif($em->getRepository('SDFBilletterieBundle:Tarif')->find($id));
            $billetCree->setUtilisateur($userRefActif);

            $em->persist($billetCree);
            $em->flush();

            $this->instantLog($userRefActif, "Billet ".$billetCree->getId()." généré dans la BDD pour l'user ");

            try {
                // CONNEXION A PAYUTC
                $payutcClient = new AutoJsonClient("https://api.nemopay.net/services/", "WEBSALE", array(CURLOPT_PROXY => 'proxyweb.utc.fr:3128', CURLOPT_TIMEOUT => 5), "Payutc Json PHP Client", array(), "payutc", $payutcAppKey);
                
                $arrayItems = array(array($billetDispo->getIdPayutc()));
                $item = json_encode($arrayItems);
                //return new Response($item);
                $billetIds = array();
                $billetIds[] = $billetCree->getTarif()->getIdPayutc();
                $returnURL = 'http://' . $_SERVER["HTTP_HOST"].$this->get('router')->generate('sdf_billetterie_routingPostPaiement',array('id'=>$billetCree->getId()));
                $callback_url = 'http://' . $_SERVER["HTTP_HOST"].$this->get('router')->generate('sdf_billetterie_callbackDePAYUTC',array('id'=>$billetCree->getId()));
                //return new Response($item);
                $c = $payutcClient->apiCall('createTransaction',
                    array("fun_id" => $payutcFunID,
                        "items" => $item,
                        "return_url" => $returnURL,
                        "callback_url" => $callback_url,
                        "mail" => $userRefActif->getEmail()
                        ));

                $billetCree->setIdPayutc($c->tra_id);

                $em->persist($billetCree);
                $em->flush();

                $this->instantLog($userRefActif, "Connexion réussie à Payutc dans le cadre de l'achat du billet ".$billetCree->getId()." associé à l'identifiant Payutc ".$c->tra_id);


                return $this->redirect($c->url);
            } catch (JsonException $e){


                $log1 = new Log();
                $log1->setUser($userRefActif);
                $log1->setContent("Connexion à Payutc a échoué dans le cadre de l'achat du billet ".$billetCree->getId());
                $log1->setDate(new \DateTime());

                $em->persist($log1);
                $em->flush();

                $em->remove($billetCree);
                $em->flush();

                //return new Response($e);
                return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
            }
    }

    public function callbackFromPayutcAction($id){
        $em = $this->getDoctrine()->getManager();
        $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');
        $billet = $repoBillets->find($id);
        if (gettype($billet) == 'NULL') return new Response("échec d'obtention du billet");
        //if ($billet->getValide() == true) return new Response("déjà validé");

        // CONNEXION A PAYUTC
        $payutcClient = new AutoJsonClient("https://api.nemopay.net/services/", "WEBSALE", array(CURLOPT_PROXY => 'proxyweb.utc.fr:3128', CURLOPT_TIMEOUT => 5), "Payutc Json PHP Client", array(), "payutc", $payutcAppKey);
        $data = $payutcClient->apiCall('getTransactionInfo',
            array('fun_id' => $payutcFunID,
                'tra_id' => $billet->getIdPayutc()
                )
            );

        if ($data->status == "V"){
            $billet->setValide(true);
            $em->persist($billet);

            $log1 = new Log();
            $log1->setUser($billet->getUtilisateur());
            $log1->setContent("Billet ".$billet->getId()." validé par Payutc : payé ");
            $log1->setDate(new \DateTime());

            $em->persist($log1);
            $em->flush();

            $message = \Swift_Message::newInstance()->setSubject('Votre billet pour la Soirée des Finaux 2015')
                ->setFrom($email)
                ->setTo($billet->getUtilisateur()->getEmail())
                ->setBody($this->renderView('SDFBilletterieBundle:billet:mailenvoi.html.twig'),'text/html');

            $this->get('mailer')->send($message);

        } elseif ($data->status == 'A') {

            $log1 = new Log();
            $log1->setUser();
            $log1->setContent("Billet ".$billet->getId()." invalidé par Payutc : non payé, paiement avorté ");
            $log1->setDate(new \DateTime());

            $em->persist($log1);
            $em->flush();

            $em->remove($billet);
            $em->flush();
        } else {
            $log1 = new Log();
            $log1->setUser();
            $log1->setContent("Billet ".$billet->getId()." invalidé par Payutc : non payé, paiement non abouti ");
            $log1->setDate(new \DateTime());

            $em->persist($log1);
            $em->flush();

            $em->remove($billet);
            $em->flush();
        }

        var_dump($data);



        
        $em->flush();

        return new Response('ok');
    }

    public function callbackFromPayutcByIdAction($id){
        $payutcClient = new AutoJsonClient("https://api.nemopay.net/services/", "WEBSALE", array(CURLOPT_PROXY => 'proxyweb.utc.fr:3128', CURLOPT_TIMEOUT => 5), "Payutc Json PHP Client", array(), "payutc", $payutcAppKey);
        $data = $payutcClient->apiCall('getTransactionInfo',
            array('fun_id' => $payutcFunID,
                'tra_id' => $id
                )
            );

        var_dump($data);
        

        return new Response('ok');
    }

    public function routingPostPayAction($id){
        $em = $this->getDoctrine()->getManager();
        $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');

        $billet = $repoBillets->find($id);

        if(gettype($billet) == 'NULL') return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));

        if($billet->getValide()) return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'billetSuccess')));

        else return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',array('message'=>'achatBilletError')));
    }

    public function relancerTransactionAction($id){

        $dsn = 'mysql:dbname='.$this->PDOdatabase.';host='.$this->PDOhost;
        $tempUser = $this->user;
        $password = $this->password;

        try {
            $bdd = new \PDO($dsn, $tempUser, $password);

        } catch (\PDOException $e) {
            echo 'Connexion échouée : ' . $e->getMessage();
            exit();
        }

        $requete = $bdd->query('SELECT * FROM Billet WHERE id = '.$id);
        $resultat = $requete->fetch();
        if($resultat == false) return new Response('Billet introuvable');
        if($resultat['valide']) return new Response('Billet déjà validé !');
        return $this->redirect('http://payutc.nemopay.net/validation?tra_id='.$resultat['idPayutc']);
    }

    public function annulerBilletInvalideAction($id){

        try {
          checkUserExists();
          if ($_SESSION['typeUser'] == 'cas') $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS')
                ->findOneBy(array('loginCAS' => $login));
          else $userActif = $em->getRepository('SDFBilletterieBundle:UtilisateurExterieur')
                ->findOneBy(array('login' => $login));
          $userRefActif = $userActif->getUser();
          /* LA CONNEXION EST VÉRIFIÉE        ON VÉRIFIE LES ACCÈS AU BILLET */
          checkConsultationRights($userRefActif->getId(),$id);
        } catch (NotFoundUserException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_homepage'));
        } catch (DeniedAccessException $e) {
          return $this->redirect($this->generateUrl('sdf_billetterie_indexBilletterie',
            array('message'=>'accessBillet')));
        }

        /* ACCÈS AU BILLET VÉRIFIÉ
    
        ON FAIT MAINTENANT L'ACTION VOULUE */

        if($billet->getValide() == true) return new Response('Le billet a été validé !');

        $em->remove($billet);
        $em->flush();

        return new Response('Le billet a bien été annulé !');
    }

    public function testbugAction(){
        // FONCTION CREEE POUR TESTER L'URL DE REDIRECTION VERS LA TRANSACTION

        $payutcClient = new AutoJsonClient("https://api.nemopay.net/services/", "WEBSALE", array(CURLOPT_PROXY => 'proxyweb.utc.fr:3128', CURLOPT_TIMEOUT => 5), "Payutc Json PHP Client", array(), "payutcdev", $payutcAppKey);
                
                $arrayItems = array(array(3201));
                $item = json_encode($arrayItems);
                //return new Response($item);
                $returnURL = 'http://google.fr/test';
                $callback_url = 'http://google.fr/test';
                //return new Response($item);
                $c = $payutcClient->apiCall('createTransaction',
                    array("fun_id" => $payutcFunID,
                        "items" => $item,
                        "return_url" => $returnURL,
                        "callback_url" => $callback_url,
                        "mail" => 'ericgourlaouen@airpost.net'
                        ));

                return new Response($c->url);
    }

    public function getEmailsNonValideAction(){

        $em=$this->getDoctrine()->getManager();
        $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');

        $requete = $em->createQuery('SELECT u FROM SDFBilletterieBundle:Billet b JOIN b.utilisateur u WHERE b.valide = FALSE')
                ->getResult();    

        $reponse = "";
        foreach($requete as $user){
            $reponse .= ", ".$user->getEmail();
        }
        return new Response($reponse);
    }

    public function checkValidBarcodeAction($id){
      $trueBarcode = ($id-($id%10))/10;
      $em=$this->getDoctrine()->getManager();
      $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');
      $billet = "";
      $billet = $repoBillets->findOneBy(array('barcode' => $trueBarcode));

      return new Response(var_dump($billet->getValide()));
    }

    public function checkValidNumBilletAction($id){

      $em = $this->getDoctrine()->getManager();

      // ON VERIFIE QU'IL Y A BIEN UN NUM DE BILLET ASSOCIE

      $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');
      $repoKeys = $em->getRepository('SDFBilletterieBundle:Appkey');
      $repoCAS = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS');

      if (gettype($id) == 'NULL') return automatedJsonResponse(array("isValid" => 'noId'));

      // ON VERIFIE QU'IL Y A UN BILLET ASSOCIE

      $billet = $repoBillets->find($id);
      if (gettype($billet) == 'NULL') return automatedJsonResponse(array("isValid" => 'noBillet'));
      if($billet->getConsomme()) return automatedJsonResponse(array("isValid" => 'alreadyUsed'));
      if(!$billet->getValide()) return automatedJsonResponse(array("isValid" => 'notValid'));
      if (!isset($_GET['key']) || gettype($repoKeys->findOneBy(array('relationKey' => $_GET['key']))) == 'NULL')
        return automatedJsonResponse(array('isValid' => 'invalidKey'));

      $utilisateurConcerne = $billet->getUtilisateur();
      $userCAS = $repoCAS->findOneBy(array('user' => $utilisateurConcerne));

      if(gettype($userCAS) != 'NULL'){
        $ginger = json_decode(file_get_contents('https://assos.utc.fr/ginger/v1/'.$userCAS->getLoginCAS().'?key='.$gingerKey));
        try {
          $adulte = $ginger->is_adulte;
        } catch (Exception $e) {
          $adulte = true;
        }
      } else {
        $adulte = true;
      }

      $tabReponse = array(
        "isValid" => "ok",
        "nom" => $billet->getNom(),
        "prenom" => $billet->getPrenom(),
        "majeur" => $adulte
        );

      $this->instantLog($billet->getUtilisateur(),"Numéro associé au billet ".$billet->getId()." lu");

      return automatedJsonResponse($tabReponse);
    }

    public function getNFCAssociatedBilletsAction($id){

      $em = $this->getDoctrine()->getManager();
      try {
        $gingerResult = json_decode(@file_get_contents('https://assos.utc.fr/ginger/v1/badge/'.
          $id.'?key='.$gingerKey));
        if(gettype($gingerResult) != 'NULL'){
          $loginAssocie = $gingerResult->login;
          $adulte = $gingerResult->is_adulte;
        } else {
          return automatedJsonResponse(array('isValide' => 'noLoginFound'));
        }
      } catch (ContextErrorException $e) {
        return automatedJsonResponse(array('isValide' => 'noLoginFound'));
      }


      $repoKeys = $em->getRepository('SDFBilletterieBundle:Appkey');
      if (!isset($_GET['key']) || gettype($repoKeys->findOneBy(array('relationKey' => $_GET['key']))) == 'NULL')
        return automatedJsonResponse(array('isValide' => 'invalidKey'));

      $repoUserCas = $em->getRepository('SDFBilletterieBundle:UtilisateurCAS');
      $userCASConcerne = $repoUserCas->findOneBy(array('loginCAS' => $loginAssocie));

      if(gettype($userCASConcerne) == 'NULL') return automatedJsonResponse(array('isValide' => 'loginNotInDatabase'));

      $billetsAffiches = array('isValide' => 'yes','isAdulte' => $adulte);

      $billets = $em->getRepository('SDFBilletterieBundle:Billet')->findBy(array('utilisateur' => $userCASConcerne->getUser()));
      $i=0;
      foreach($billets as $billet){
          if ($billet->getValide() && !$billet->getConsomme()){
            $billetsAffiches[$i++] = array('id' => $billet->getId(),
            'nom' => $billet->getNom(),
            'prenom' => $billet->getPrenom());

            $this->instantLog($billet->getUtilisateur(),"Badge associé au billet ".$billet->getId()." lu");
          }
      }

      return automatedJsonResponse($billetsAffiches);
    }

    public function checkByNamePortionAction($name){

      $em = $this->getDoctrine()->getManager();

      $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');
      $repoKeys = $em->getRepository('SDFBilletterieBundle:Appkey');

      if(!isset($name)) return automatedJsonResponse(array('isValide' => 'unsetName'));

      if (!isset($_GET['key']) || gettype($repoKeys->findOneBy(array('relationKey' => $_GET['key']))) == 'NULL')
        return automatedJsonResponse(array('isValide' => 'invalidKey'));

      $bdd = new \PDO('mysql:host='.$this->PDOhost.';dbname='.$this->PDOdatabase.';charset=utf8',$this->user,$this->password);
      $requete = "SELECT id, nom, prenom, valide, consomme FROM Billet WHERE prenom LIKE %$name% OR nom LIKE %$name%";

      $resultat = $bdd->query($requete);
      $billetsAffiches = array('isValide' => 'yes');
      $i=0;

      while($donnees = $resultat->fetch()){
        if ($donnees['valide'] && !$donnees['consomme']){
                    $billetsAffiches[$i++] = array('id' => $donnees['id'],
                    'nom' => $donnees['nom'],
                    'prenom' => $donnees['prenom']);
                  }
      }

      return automatedJsonResponse($billetsAffiches);

    }

    public function sendMailInfosAction(){

      send_time_limit(180);

      $em = $this->getDoctrine()->getManager();
      $repoUser = $em->getRepository('SDFBilletterieBundle:Utilisateur');
      $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');

      $listeUsers = $repoUser->findAll();

      foreach($listeUsers as $user){
        if ($user->getId() <= 606) break; else {
          $billet = $repoBillets->findOneBy(array('utilisateur' => $user));
          if (gettype($billet) != 'NULL'){
            $message = \Swift_Message::newInstance()->setSubject('Soirée des Finaux 2015 - Infos Pratiques')
                  ->setFrom('soireedesfinaux@assos.utc.fr')
                  ->setTo($user->getEmail())
                  ->setBody($this->renderView('SDFBilletterieBundle:billet:mailinfos.html.twig'),'text/html');

            $this->get('mailer')->send($message);
            echo "fait pour : ".$user->getId()."<br />"; }
        }
      }
      return new Response("OK");
    }

    public function validateBilletAction($id){

      $em = $this->getDoctrine()->getManager();
      $repoBillets = $em->getRepository('SDFBilletterieBundle:Billet');

      $repoKeys = $em->getRepository('SDFBilletterieBundle:Appkey');
      if (!isset($_GET['key']) || gettype($repoKeys->findOneBy(array('relationKey' => $_GET['key']))) == 'NULL')
        return automatedJsonResponse(array('validation' => 'invalidKey'));

      $billet = $repoBillets->find($id);

      if (gettype($billet) == 'NULL' || !$billet->getValide())
        return automatedJsonResponse(array('validation' => 'noBillet'));

      if ($billet->getConsomme())
        return automatedJsonResponse(array('validation' => 'alreadyConsumed'));

      $billet->setConsomme(true);

      $this->instantLog($billet->getUtilisateur(),"Billet ".$billet->getId()." consommé");
      $em->persist($billet);
      $em->flush();

      return automatedJsonResponse(array('validation' => 'ok'));

    }
}
