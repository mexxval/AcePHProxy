
$(function () {
	var camera, scene, renderer;
	var controls;

	init();
	animate();

	function init() {

		camera = new THREE.PerspectiveCamera( 75, window.innerWidth / window.innerHeight, 0.1, 1000 );
		camera.position.z = 500;
		//camera.position.x = 500; // прикольно
		//camera.position.y = 500;

		scene = new THREE.Scene();

		var element = document.getElementById('stats');
			var object = new THREE.CSS3DObject( element );
			object.position.x = -500;
			object.position.y = 0;
			object.position.z = 0;
			object.rotation.y = 0.5;
			scene.add( object );

			//
		var element = document.getElementById('maincontent');
			var object = new THREE.CSS3DObject( element );
			object.position.x = 0;
			object.position.y = 0;
			object.position.z = 0;
			scene.add( object );







		renderer = new THREE.CSS3DRenderer();
		renderer.setSize( window.innerWidth, window.innerHeight );
		//renderer.domElement.style.position = 'absolute';
		document.body.appendChild( renderer.domElement );

		//

		controls = new THREE.TrackballControls( camera, renderer.domElement );
		controls.rotateSpeed = 0.0;
		controls.zoomSpeed = 0.01;
		controls.minDistance = 400;
		controls.maxDistance = 700;



		window.addEventListener( 'resize', onWindowResize, false );

	}

	function onWindowResize() {
		camera.aspect = window.innerWidth / window.innerHeight;
		camera.updateProjectionMatrix();

		renderer.setSize( window.innerWidth, window.innerHeight );
	}

	function animate() {
		requestAnimationFrame( animate );
		TWEEN.update();
		render();
		controls.update();
	}

	function render() {

		renderer.render( scene, camera );

	}

});

/*
	function transform( targets, duration ) {

		TWEEN.removeAll();

		for ( var i = 0; i < objects.length; i ++ ) {

			var object = objects[ i ];
			var target = targets[ i ];

			new TWEEN.Tween( object.position )
				.to( { x: target.position.x, y: target.position.y, z: target.position.z }, Math.random() * duration + duration )
				.easing( TWEEN.Easing.Exponential.InOut )
				.start();

			new TWEEN.Tween( object.rotation )
				.to( { x: target.rotation.x, y: target.rotation.y, z: target.rotation.z }, Math.random() * duration + duration )
				.easing( TWEEN.Easing.Exponential.InOut )
				.start();

		}

		new TWEEN.Tween( this )
			.to( {}, duration * 2 )
			.start();

	}

*/

